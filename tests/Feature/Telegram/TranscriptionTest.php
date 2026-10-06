<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Telegram\Transcription\NullTranscriber;
use Obrazmisli\Agentio\Telegram\Transcription\OpenAiTranscriber;
use Obrazmisli\Agentio\Telegram\Transcription\Transcriber;
use Obrazmisli\Agentio\Telegram\Transcription\TranscriptionException;
use Obrazmisli\Agentio\Telegram\Transcription\TranscriptionManager;
use Obrazmisli\Agentio\Telegram\Transcription\WhisperCliTranscriber;

it('resolves the transcriber the developer chose', function (?string $driver, string $class) {
    config(['agentio.telegram.transcription.driver' => $driver]);
    app()->forgetInstance(TranscriptionManager::class);

    expect(app(Transcriber::class))->toBeInstanceOf($class);
})->with([
    [null, NullTranscriber::class],
    ['none', NullTranscriber::class],
    ['openai', OpenAiTranscriber::class],
    ['whisper', WhisperCliTranscriber::class],
    ['yandex', NullTranscriber::class],
]);

it('lets an application add its own driver', function () {
    config(['agentio.telegram.transcription.driver' => 'custom']);
    app()->forgetInstance(TranscriptionManager::class);
    app(TranscriptionManager::class)->extend('custom', fn (): Transcriber => new class implements Transcriber
    {
        public function transcribe(string $audio, string $filename): string
        {
            return 'свой драйвер';
        }
    });

    expect(app(Transcriber::class)->transcribe('a', 'voice.oga'))->toBe('свой драйвер');
});

it('explains that voice messages are not set up', function () {
    config(['agentio.telegram.transcription.driver' => 'yandex']);
    app()->forgetInstance(TranscriptionManager::class);

    app(Transcriber::class)->transcribe('a', 'voice.oga');
})->throws(TranscriptionException::class, 'распознавание голосовых не настроено (неизвестный драйвер yandex)');

it('sends the voice message to an OpenAI-compatible API', function () {
    config(['agentio.telegram.transcription' => ['driver' => 'openai', 'url' => 'https://stt.test/v1/', 'key' => 'sk-test', 'model' => 'whisper-large-v3', 'language' => 'ru']]);
    app()->forgetInstance(TranscriptionManager::class);
    Http::fake(['stt.test/*' => Http::response(['text' => ' Принимаю рекомендации. '])]);

    expect(app(Transcriber::class)->transcribe('OggS', 'voice.oga'))->toBe('Принимаю рекомендации.');

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->mapWithKeys(fn (array $part): array => [$part['name'] => [$part['contents'], $part['filename'] ?? null]]);

        return $request->url() === 'https://stt.test/v1/audio/transcriptions'
            && $request->header('Authorization') === ['Bearer sk-test']
            && $parts->sortKeys()->all() === ['file' => ['OggS', 'voice.ogg'], 'language' => ['ru', null], 'model' => ['whisper-large-v3', null], 'response_format' => ['json', null]];
    });
});

it('sends no key and no language when none is set', function () {
    config(['agentio.telegram.transcription' => ['driver' => 'openai', 'url' => 'https://stt.test/v1', 'key' => null, 'language' => null]]);
    app()->forgetInstance(TranscriptionManager::class);
    Http::fake(['stt.test/*' => Http::response(['text' => 'Текст'])]);

    expect(app(Transcriber::class)->transcribe('OggS', 'voice.ogg'))->toBe('Текст');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization') && $request->hasFile('model', 'whisper-1') && ! $request->hasFile('language'));
});

it('reports an error of the transcription API', function () {
    config(['agentio.telegram.transcription' => ['driver' => 'openai', 'url' => 'https://stt.test/v1', 'key' => null]]);
    app()->forgetInstance(TranscriptionManager::class);
    Http::fake(['stt.test/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    app(Transcriber::class)->transcribe('OggS', 'voice.oga');
})->throws(TranscriptionException::class, 'The transcription API answered HTTP 401: Invalid API key');

it('reports an unreachable transcription API', function () {
    config(['agentio.telegram.transcription' => ['driver' => 'openai', 'url' => 'https://stt.test/v1']]);
    app()->forgetInstance(TranscriptionManager::class);
    Http::fake(['stt.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    app(Transcriber::class)->transcribe('OggS', 'voice.oga');
})->throws(TranscriptionException::class, 'The transcription API is unreachable: cURL error 28: Operation timed out');

it('transcribes locally with ffmpeg and whisper-cli', function () {
    $model = temporaryDirectory().'/ggml-small.bin';
    touch($model);
    Process::fake([
        '*ffmpeg*' => Process::result(),
        '*whisper-cli*' => Process::result(" Нужно принимать оплату по СБП.\n"),
    ]);

    $text = (new WhisperCliTranscriber('whisper-cli', $model, 'ffmpeg', temporaryDirectory()))->transcribe('OggS', 'voice.oga');

    expect($text)->toBe('Нужно принимать оплату по СБП.');
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'ffmpeg' && in_array('16000', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'whisper-cli' && in_array($model, $process->command, true) && in_array('ru', $process->command, true));
});

it('fails clearly without the whisper model or when ffmpeg fails', function () {
    expect(fn () => (new WhisperCliTranscriber('whisper-cli', '/nonexistent.bin', 'ffmpeg', temporaryDirectory()))->transcribe('a', 'v.oga'))
        ->toThrow(TranscriptionException::class, 'The whisper.cpp model is not found');

    $model = temporaryDirectory().'/m.bin';
    touch($model);
    Process::fake(['*ffmpeg*' => Process::result(errorOutput: 'Invalid data found', exitCode: 1)]);

    expect(fn () => (new WhisperCliTranscriber('whisper-cli', $model, 'ffmpeg', temporaryDirectory()))->transcribe('a', 'v.oga'))
        ->toThrow(TranscriptionException::class, 'ffmpeg could not convert the voice message: Invalid data found');
});
