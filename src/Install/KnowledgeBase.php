<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * The knowledge base tree the agents rely on (the "{{kb.<key>}}" placeholders of the stubs).
 *
 * Article templates live in stubs/youtrack/articles/<key>.md; the "guide" article is a copy of the manual of
 * the package (resources/docs/AUTONOMOUS_WORKFLOW.md). Under "Системная аналитика" only the common
 * requirements are created: the agentio-system-analyst skill adds module articles, with the data model article
 * and the feature articles of each module, as the product grows.
 */
final readonly class KnowledgeBase
{
    /**
     * Key => [title, parent key], parents before children.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    public const array ARTICLES = [
        'overview' => ['Обзор продукта', null],
        'analysis' => ['Системная аналитика', null],
        'analysis.common' => ['Общие требования', 'analysis'],
        'ideas' => ['Идеи', null],
        'architecture' => ['Архитектура', null],
        'architecture.overview' => ['Архитектура: обзор', 'architecture'],
        'adr' => ['ADR', 'architecture'],
        'adr.001' => ['ADR-001: Базовые архитектурные решения', 'adr'],
        'process' => ['Процесс разработки', null],
        'guide' => ['Руководство по автоматизации', 'process'],
        'glossary' => ['Глоссарий', null],
    ];

    /** The article agentio keeps equal to its manual; every other article belongs to the people and agents. */
    public const string GUIDE = 'guide';

    public function __construct(private string $packagePath) {}

    /**
     * The knowledge base of the installed package.
     */
    public static function ofPackage(): self
    {
        return new self(dirname(__DIR__, 2));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::ARTICLES);
    }

    public function title(string $key): string
    {
        return self::ARTICLES[$key][0] ?? $key;
    }

    public function parent(string $key): ?string
    {
        return self::ARTICLES[$key][1] ?? null;
    }

    /**
     * Content of a new article, with the placeholders rendered.
     */
    public function content(string $key, Placeholders $placeholders): string
    {
        $file = $key === self::GUIDE
            ? $this->packagePath.'/resources/docs/AUTONOMOUS_WORKFLOW.md'
            : $this->packagePath.'/stubs/youtrack/articles/'.$key.'.md';

        return is_file($file) ? $placeholders->render((string) file_get_contents($file)) : '';
    }
}
