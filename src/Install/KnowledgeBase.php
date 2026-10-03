<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * The knowledge base tree the agents rely on (the "{{kb.<key>}}" placeholders of the stubs).
 *
 * Article templates live in stubs/youtrack/articles/<key>.md; the "guide" article is the installed
 * docs/AUTONOMOUS_WORKFLOW.md.
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
        'analysis.business' => ['1. Бизнес-контекст и цели', 'analysis'],
        'analysis.users' => ['2. Пользователи, роли и сценарии', 'analysis'],
        'analysis.functional' => ['3. Функциональные требования и бизнес-правила', 'analysis'],
        'analysis.data' => ['4. Данные: сущности, атрибуты, связи, жизненный цикл', 'analysis'],
        'analysis.integrations' => ['5. Интеграции и интерфейсы', 'analysis'],
        'analysis.nfr' => ['6. Нефункциональные требования', 'analysis'],
        'analysis.constraints' => ['7. Ограничения, допущения и риски', 'analysis'],
        'architecture' => ['Архитектура', null],
        'architecture.overview' => ['Архитектура: обзор', 'architecture'],
        'architecture.data_model' => ['Модель данных', 'architecture'],
        'adr' => ['ADR', 'architecture'],
        'adr.001' => ['ADR-001: Базовые архитектурные решения', 'adr'],
        'process' => ['Процесс разработки', null],
        'guide' => ['Руководство по автоматизации', 'process'],
        'glossary' => ['Глоссарий', null],
    ];

    public function __construct(private string $stubsPath) {}

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
        $file = $key === 'guide'
            ? $this->stubsPath.'/docs/AUTONOMOUS_WORKFLOW.md'
            : $this->stubsPath.'/youtrack/articles/'.$key.'.md';

        return is_file($file) ? $placeholders->render((string) file_get_contents($file)) : '';
    }
}
