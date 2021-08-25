<?php

/** @noinspection PhpPluralMixedCanBeReplacedWithArrayInspection */

namespace Unleash\Client\DTO;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use JetBrains\PhpStorm\Pure;
use JsonException;
use LogicException;
use Unleash\Client\Enum\VariantPayloadType;

final class DefaultVariantPayload implements VariantPayload
{
    /**
     * @var string
     */
    private $type;
    /**
     * @var string
     */
    private $value;
    public function __construct(
        #[\JetBrains\PhpStorm\ExpectedValues(valuesFromClass: \Unleash\Client\Enum\VariantPayloadType::class)]
        string $type,
        string $value
    )
    {
        $this->type = $type;
        $this->value = $value;
    }
    /**
     * @codeCoverageIgnore
     */
    #[ExpectedValues(valuesFromClass: VariantPayloadType::class)]
    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @throws JsonException
     *
     * @return array<mixed>
     */
    public function fromJson(): array
    {
        if ($this->type !== VariantPayloadType::JSON) {
            throw new LogicException(
                sprintf("Only payloads of type '%s' can be converted from json, this payload has type '%s'", VariantPayloadType::JSON, $this->type)
            );
        }

        return json_decode($this->value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string>
     */
    #[Pure]
    #[ArrayShape(['type' => 'string', 'value' => 'string'])]
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->getValue(),
        ];
    }
}
