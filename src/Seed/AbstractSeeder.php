<?php

declare(strict_types=1);

namespace Anokii\Seed;

use Waaseyaa\CLI\CliIO;

/**
 * Base for an idempotent Anokii data seeder.
 *
 * Every instance writes a seeder with the same skeleton: iterate a list of seed
 * records, skip any record that already exists, create the rest, and report a
 * running count to the CLI. Re-running is always safe. This base captures that
 * skeleton and the skip/create/report bookkeeping; the subclass supplies only
 * the data and the two domain operations (does-it-exist, create-it) via
 * {@see seedRecords()}.
 *
 * Built strictly on the framework CLI: {@see CliIO} is the only I/O surface, so a
 * subclass can be wired into a ServiceProvider's nativeCommands() or a
 * CommandDefinition handler unchanged.
 *
 * @api
 */
abstract class AbstractSeeder
{
    private int $created = 0;
    private int $skipped = 0;

    /**
     * A short noun for the records this seeder creates, used in the summary line
     * (for example 'page', 'document'). Singular; pluralized naively in output.
     *
     * @api
     */
    abstract protected function noun(): string;

    /**
     * Perform the seed. A subclass implements this by calling {@see seed()} (or
     * {@see seedIf()}) once per record, then may write any extra summary. The
     * base resets the counters before the call and prints the standard summary
     * after, so the subclass body is just the per-record loop.
     *
     * @api
     */
    abstract protected function seedRecords(CliIO $io): void;

    /**
     * Run the seeder: reset counters, delegate to {@see seedRecords()}, print the
     * idempotency summary. Returns 0 on success.
     *
     * @api
     */
    final public function run(CliIO $io): int
    {
        $this->created = 0;
        $this->skipped = 0;

        $this->seedRecords($io);

        $io->writeln($this->summaryLine());

        return 0;
    }

    /**
     * Seed one record idempotently. When $exists is true the record is skipped
     * (and logged); otherwise $create is invoked, the creation logged, and the
     * created-count advanced. Returns true when the record was created.
     *
     * Usage inside seedRecords():
     *   foreach ($seeds as $name => $data) {
     *       $this->seedIf(
     *           $io,
     *           $name,
     *           $this->repo->findByName($name) !== null,
     *           fn () => $this->repo->create($data),
     *       );
     *   }
     *
     * @param callable():void $create
     *
     * @api
     */
    protected function seedIf(CliIO $io, string $label, bool $exists, callable $create): bool
    {
        if ($exists) {
            $this->skip($io, $label);

            return false;
        }

        $create();
        $this->markCreated($io, $label);

        return true;
    }

    /**
     * Record and log a skipped (already-existing) record.
     *
     * @api
     */
    protected function skip(CliIO $io, string $label): void
    {
        ++$this->skipped;
        $io->writeln(sprintf('Skip %s (exists): %s', $this->noun(), $label));
    }

    /**
     * Record and log a freshly created record. Call this directly when a
     * subclass cannot express creation as a single {@see seedIf()} callable.
     *
     * @api
     */
    protected function markCreated(CliIO $io, string $label): void
    {
        ++$this->created;
        $io->writeln(sprintf('Seeded %s: %s', $this->noun(), $label));
    }

    /**
     * How many records this run created.
     *
     * @api
     */
    protected function createdCount(): int
    {
        return $this->created;
    }

    /**
     * How many records this run skipped because they already existed.
     *
     * @api
     */
    protected function skippedCount(): int
    {
        return $this->skipped;
    }

    private function summaryLine(): string
    {
        if ($this->created === 0) {
            return sprintf('Nothing to do, all %s records exist.', $this->noun());
        }

        return sprintf('Seeded %d %s record(s).', $this->created, $this->noun());
    }
}
