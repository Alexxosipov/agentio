<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\TelegramText;

it('turns the Markdown of the agents into the HTML of Telegram and escapes the rest', function () {
    expect(TelegramText::html("## Вопросы\n**В1.** Оплата <частями> & `orders.paid_at`?\n- а) _первый_ платёж"))
        ->toBe("<b>Вопросы</b>\n<b>В1.</b> Оплата &lt;частями&gt; &amp; <code>orders.paid_at</code>?\n- а) <i>первый</i> платёж")
        ->and(TelegramText::html("Код:\n```php\n\$a = 1 < 2;\n```"))->toBe("Код:\n<pre>\$a = 1 &lt; 2;</pre>")
        ->and(TelegramText::html('snake_case_name stays'))->toBe('snake_case_name stays');
});

it('removes the Markdown markers for the plain fallback', function () {
    expect(TelegramText::plain("## Итог\n**Сделано:** `composer test`"))->toBe("Итог\nСделано: composer test");
});

it('splits a long text at paragraphs, then lines, then hard', function () {
    $paragraph = str_repeat('а', 30);
    $chunks = TelegramText::chunks(implode("\n\n", array_fill(0, 5, $paragraph)), 70);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0])->toBe($paragraph."\n\n".$paragraph)
        ->and(TelegramText::chunks(str_repeat('б', 150), 70))->toHaveCount(3)
        ->and(TelegramText::chunks("  \n "))->toBe([]);
});

it('cuts a text with an ellipsis', function () {
    expect(TelegramText::limit('Короткий', 20))->toBe('Короткий')
        ->and(TelegramText::limit('Очень длинный текст', 10))->toBe('Очень дли…');
});
