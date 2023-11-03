<?php

namespace Unleash\Client\Repository;

use Exception;
use JsonException;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Unleash\Client\Configuration\UnleashConfiguration;
use Unleash\Client\DTO\Constraint;
use Unleash\Client\DTO\DefaultConstraint;
use Unleash\Client\DTO\DefaultFeature;
use Unleash\Client\DTO\DefaultSegment;
use Unleash\Client\DTO\DefaultStrategy;
use Unleash\Client\DTO\DefaultVariant;
use Unleash\Client\DTO\DefaultVariantOverride;
use Unleash\Client\DTO\DefaultVariantPayload;
use Unleash\Client\DTO\Feature;
use Unleash\Client\DTO\Segment;
use Unleash\Client\DTO\Variant;
use Unleash\Client\Enum\CacheKey;
use Unleash\Client\Enum\Stickiness;
use Unleash\Client\Event\FetchingDataFailedEvent;
use Unleash\Client\Event\UnleashEvents;
use Unleash\Client\Exception\HttpResponseException;
use Unleash\Client\Exception\InvalidValueException;

/**
 * @phpstan-type ConstraintArray array{
 *     contextName: string,
 *     operator: string,
 *     values?: array<string>,
 *     value?: string,
 *     inverted?: bool,
 *     caseInsensitive?: bool
 * }
 * @phpstan-type VariantArray array{
 *      contextName: string,
 *      name: string,
 *      weight: int,
 *      stickiness?: string,
 *      payload?: VariantPayload,
 *      overrides?: array<VariantOverride>,
 *  }
 * @phpstan-type VariantPayload array{
 *        type: string,
 *        value: string,
 *    }
 * @phpstan-type VariantOverride array{
 *       contextName: string,
 *       values: array<string>,
 *       type:string,
 *       value: string,
 *   }
 */
final class DefaultUnleashRepository implements UnleashRepository
{
    /**
     * @readonly
     * @var \Psr\Http\Client\ClientInterface
     */
    private $httpClient;
    /**
     * @readonly
     * @var \Psr\Http\Message\RequestFactoryInterface
     */
    private $requestFactory;
    /**
     * @readonly
     * @var \Unleash\Client\Configuration\UnleashConfiguration
     */
    private $configuration;
    public function __construct(ClientInterface $httpClient, RequestFactoryInterface $requestFactory, UnleashConfiguration $configuration)
    {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->configuration = $configuration;
    }
    /**
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function findFeature(string $featureName): ?Feature
    {
        $features = $this->getFeatures();
        assert(is_array($features));

        return $features[$featureName] ?? null;
    }

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws JsonException
     *
     * @return iterable<Feature>
     */
    public function getFeatures(): iterable
    {
        $features = $this->getCachedFeatures();
        if ($features === null) {
            if (!$this->configuration->isFetchingEnabled()) {
                if (!$data = $this->getBootstrappedResponse()) {
                    throw new LogicException('Fetching of Unleash api is disabled but no bootstrap is provided');
                }
            } else {
                $request = $this->requestFactory
                    ->createRequest('GET', $this->configuration->getUrl() . 'client/features')
                    ->withHeader('UNLEASH-APPNAME', $this->configuration->getAppName())
                    ->withHeader('UNLEASH-INSTANCEID', $this->configuration->getInstanceId())
                    ->withHeader('Unleash-Client-Spec', '4.3.2')
                ;

                foreach ($this->configuration->getHeaders() as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                try {
                    $response = $this->httpClient->sendRequest($request);
                    if ($response->getStatusCode() === 200) {
                        $data = (string) $response->getBody();
                        $this->setLastValidState($data);
                    } else {
                        throw new HttpResponseException("Invalid status code: '{$response->getStatusCode()}'");
                    }
                } catch (Exception $exception) {
                    $this->configuration->getEventDispatcher()->dispatch(new FetchingDataFailedEvent($exception), UnleashEvents::FETCHING_DATA_FAILED);
                    $data = $this->getLastValidState();
                }
                $data = $data ?? $this->getBootstrappedResponse();
                if ($data === null) {
                    throw new HttpResponseException(sprintf(
                        'Got invalid response code when getting features and no default bootstrap provided: %s',
                        isset($response) ? $response->getStatusCode() : 'unknown response status code'
                    ), 0, $exception ?? null);
                }
            }

            $features = $this->parseFeatures($data);
            $this->setCache($features);
        }

        return $features;
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array<Feature>|null
     */
    private function getCachedFeatures(): ?array
    {
        $cache = $this->configuration->getCache();

        if (!$cache->has(CacheKey::FEATURES)) {
            return null;
        }

        $result = $cache->get(CacheKey::FEATURES, []);
        assert(is_array($result));

        return $result;
    }

    /**
     * @param array<Feature> $features
     *
     * @throws InvalidArgumentException
     */
    private function setCache(array $features): void
    {
        $cache = $this->configuration->getCache();
        $cache->set(CacheKey::FEATURES, $features, $this->configuration->getTtl());
    }

    /**
     * @throws JsonException
     *
     * @return array<Feature>
     */
    private function parseFeatures(string $rawBody): array
    {
        $features = [];
        $body = json_decode($rawBody, true, 512, 0);
        assert(is_array($body));

        $globalSegments = $this->parseSegments($body['segments'] ?? []);

        if (!isset($body['features']) || !is_array($body['features'])) {
            throw new InvalidValueException("The body isn't valid because it doesn't contain a 'features' key");
        }

        foreach ($body['features'] as $feature) {
            $strategies = [];

            foreach ($feature['strategies'] as $strategy) {
                $constraints = $this->parseConstraints($strategy['constraints'] ?? []);
                $strategyVariants = $this->parseVariants($strategy['variants'] ?? []);

                $hasNonexistentSegments = false;
                $segments = [];
                foreach ($strategy['segments'] ?? [] as $segment) {
                    if (isset($globalSegments[$segment])) {
                        $segments[] = $globalSegments[$segment];
                    } else {
                        $hasNonexistentSegments = true;
                        break;
                    }
                }
                $strategies[] = new DefaultStrategy($strategy['name'], $strategy['parameters'] ?? [], $constraints, $segments, $hasNonexistentSegments, $strategyVariants);
            }

            $featureVariants = $this->parseVariants($feature['variants'] ?? []);

            $features[$feature['name']] = new DefaultFeature($feature['name'], $feature['enabled'], $strategies, $featureVariants, $feature['impressionData'] ?? false);
        }

        return $features;
    }

    private function getBootstrappedResponse(): ?string
    {
        return $this->configuration->getBootstrapHandler()->getBootstrapContents($this->configuration->getBootstrapProvider());
    }

    private function getLastValidState(): ?string
    {
        if (!$this->configuration->getStaleCache()->has(CacheKey::FEATURES_RESPONSE)) {
            return null;
        }

        $value = $this->configuration->getStaleCache()->get(CacheKey::FEATURES_RESPONSE);
        assert(is_string($value));

        return $value;
    }

    private function setLastValidState(string $data): void
    {
        $this->configuration->getStaleCache()->set(CacheKey::FEATURES_RESPONSE, $data, $this->configuration->getStaleTtl());
    }

    /**
     * @param array<array{id: int, constraints: array<ConstraintArray>}> $segmentsRaw
     *
     * @return array<Segment>
     */
    private function parseSegments(array $segmentsRaw): array
    {
        $result = [];
        foreach ($segmentsRaw as $segmentRaw) {
            $result[$segmentRaw['id']] = new DefaultSegment($segmentRaw['id'], $this->parseConstraints($segmentRaw['constraints']));
        }

        return $result;
    }

    /**
     * @param array<ConstraintArray> $constraintsRaw
     *
     * @return array<Constraint>
     */
    private function parseConstraints(array $constraintsRaw): array
    {
        $constraints = [];

        foreach ($constraintsRaw as $constraint) {
            $constraints[] = new DefaultConstraint($constraint['contextName'], $constraint['operator'], $constraint['values'] ?? null, $constraint['value'] ?? null, $constraint['inverted'] ?? false, $constraint['caseInsensitive'] ?? false);
        }

        return $constraints;
    }

    /**
     * @param array<VariantArray> $variantsRaw
     *
     * @return array<Variant>
     */
    private function parseVariants(array $variantsRaw): array
    {
        $variants = [];

        foreach ($variantsRaw as $variant) {
            $overrides = [];
            foreach ($variant['overrides'] ?? [] as $override) {
                $overrides[] = new DefaultVariantOverride($override['contextName'], $override['values']);
            }
            $variants[] = new DefaultVariant($variant['name'], true, $variant['weight'], $variant['stickiness'] ?? Stickiness::DEFAULT, isset($variant['payload'])
                ? new DefaultVariantPayload($variant['payload']['type'], $variant['payload']['value'])
                : null, $overrides);
        }

        return $variants;
    }
}
