<?php

declare(strict_types=1);

use App\Mcp\Servers\ShoutrrrServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Tool;

/**
 * @return array<string, Tool>
 */
function registeredMcpTools(): array
{
    /** @var list<class-string<Tool>> $classes */
    $classes = (new ReflectionClass(ShoutrrrServer::class))->getProperty('tools')->getDefaultValue();

    $tools = [];
    foreach ($classes as $class) {
        $tool = app($class);
        $tools[$tool->name()] = $tool;
    }

    return $tools;
}

/**
 * Snake-case words that start with a tool verb, such as get_post or publish_now.
 * Field names ending in _id or _ids are skipped.
 *
 * @param  list<string>  $verbs
 * @return list<string>
 */
function referencedToolNames(string $text, array $verbs): array
{
    preg_match_all('/\b([a-z]+)(?:_[a-z]+)+\b/', $text, $matches, PREG_SET_ORDER);

    $names = [];
    foreach ($matches as $match) {
        if (in_array($match[1], $verbs, true) && preg_match('/_ids?$/', $match[0]) !== 1) {
            $names[] = $match[0];
        }
    }

    return array_values(array_unique($names));
}

test('every MCP tool has a snake_case name', function (): void {
    foreach (array_keys(registeredMcpTools()) as $name) {
        expect($name)->toMatch('/^[a-z]+(_[a-z]+)+$/');
    }
});

test('the server instructions and tool descriptions only name registered tools', function (): void {
    $tools = registeredMcpTools();
    $names = array_keys($tools);
    $verbs = array_values(array_unique(array_map(fn (string $name): string => explode('_', $name)[0], $names)));

    $attribute = (new ReflectionClass(ShoutrrrServer::class))->getAttributes(Instructions::class)[0];
    $texts = ['server instructions' => (string) $attribute->getArguments()[0]];
    foreach ($tools as $name => $tool) {
        $texts[$name] = $tool->description();
    }

    $unknown = [];
    foreach ($texts as $source => $text) {
        foreach (referencedToolNames($text, $verbs) as $reference) {
            if (! in_array($reference, $names, true)) {
                $unknown[] = "{$source}: {$reference}";
            }
        }
    }

    expect($unknown)->toBe([]);
});
