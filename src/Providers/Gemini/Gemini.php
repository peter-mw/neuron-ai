<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Client;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class Gemini implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
    ) {
        $this->client = new Client([
            // Since Gemini use colon ":" into the URL guzzle fire an exception using base_uri configuration.
            //'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->key,
            ]
        ]);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new MessageMapper();
    }

    protected function generateToolsPayload(): array
    {
        $tools = \array_map(function (ToolInterface $tool): array {
            $payload = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property) {
                $carry[$property->getName()] = $property->getJsonSchema();
                return $carry;
            }, []);

            if (!empty($properties)) {
                $payload['parameters'] = [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $tool->getRequiredProperties(),
                ];
            }

            return $payload;
        }, $this->tools);

        return [
            'functionDeclarations' => $tools
        ];
    }

    protected function createToolCallMessage(array $message): Message
    {
        $tools = \array_map(function (array $item): ?\NeuronAI\Tools\ToolInterface {
            if (!isset($item['functionCall'])) {
                return null;
            }

            // Gemini does not use ID. It uses the tool's name as a unique identifier.
            return $this->findTool($item['functionCall']['name'])
                ->setInputs($item['functionCall']['args'])
                ->setCallId($item['functionCall']['name']);
        }, $message['parts']);

        $result = new ToolCallMessage(
            $message['content'] ?? null,
            \array_filter($tools)
        );
        $result->setRole(MessageRole::MODEL);

        return $result;
    }
}
