<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Speech to text on this machine with whisper.cpp (https://github.com/ggml-org/whisper.cpp): ffmpeg converts the
 * voice message to 16 kHz mono WAV, whisper-cli writes the text into a .txt file next to it.
 */
final readonly class WhisperCliTranscriber implements Transcriber
{
    public function __construct(
        private string $binary,
        private string $model,
        private string $ffmpeg,
        private string $workDirectory,
        private ?string $language = 'ru',
        private int $timeout = 600,
    ) {}

    public function transcribe(string $audio, string $filename): string
    {
        if ($this->model === '' || ! is_file($this->model)) {
            throw new TranscriptionException('The whisper.cpp model is not found (AGENTIO_WHISPER_MODEL: '.($this->model === '' ? 'not set' : $this->model).').');
        }

        $directory = rtrim($this->workDirectory, '/').'/whisper-'.Str::random(12);
        mkdir($directory, 0775, true);
        $source = $directory.'/'.(preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'voice.oga');

        try {
            file_put_contents($source, $audio);

            $convert = Process::timeout($this->timeout)->run([$this->ffmpeg, '-nostdin', '-loglevel', 'error', '-y', '-i', $source, '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', $directory.'/voice.wav']);

            if ($convert->failed()) {
                throw new TranscriptionException('ffmpeg could not convert the voice message: '.trim($convert->errorOutput() ?: $convert->output()));
            }

            $command = [$this->binary, '-m', $this->model, '-f', $directory.'/voice.wav', '-nt', '-otxt', '-of', $directory.'/voice'];

            if ($this->language !== null) {
                array_push($command, '-l', $this->language);
            }

            $whisper = Process::timeout($this->timeout)->run($command);

            if ($whisper->failed()) {
                throw new TranscriptionException('whisper-cli failed: '.Str::limit(trim($whisper->errorOutput() ?: $whisper->output()), 500));
            }

            $text = is_file($directory.'/voice.txt') ? (string) file_get_contents($directory.'/voice.txt') : $whisper->output();

            return trim((string) preg_replace('/\s+/', ' ', $text));
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
}
