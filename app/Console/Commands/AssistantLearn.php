<?php

namespace App\Console\Commands;

use App\Services\Assistant\ModelTrainer;
use Illuminate\Console\Command;

class AssistantLearn extends Command
{
    protected $signature = 'assistant:learn {--trigger=schedule : schedule | admin}';

    protected $description = 'Retrain «مساعد AI» with phrases learnt from merchants; activate the model only if it passes the quality gate';

    public function handle(ModelTrainer $trainer): int
    {
        $this->info('Learned phrases: '.$trainer->learnedPhrases()->count());

        $version = $trainer->train($this->option('trigger'));

        $this->line(sprintf(
            'Version #%d: %s — hold-out %.1f%%, acceptance %d/%d, learned %d',
            $version->id,
            $version->status,
            (float) $version->holdout_accuracy * 100,
            $version->acceptance_passed,
            $version->acceptance_total,
            $version->learned_samples,
        ));

        if ($version->notes) {
            $this->warn($version->notes);
        }

        return self::SUCCESS;
    }
}
