<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Arr;

class SudokuGeneratorDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sudoku:data1 {--s|size=9} {--b|box_size=3} {--m|max_value=} {--o|no-output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public function __construct(
        public array $original_nums = [],
        public array $indices_repo = [],
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $size = (int) $this->option('size');
        $box_size = (int) $this->option('box_size');
        $max_value = (int) $this->option('max_value') ?: $size;

        $errors = array_filter(
            [
                "Size should be a multiple of box size." => $size % $box_size !== 0,
                "Size should be greater than or equal to Box size." => $size < $box_size,
                "Max value should be greater than or equal to the square of the Box size." => $max_value < ($box_size ** 2),
            ]
        );

        if (count($errors) !== 0) {
            foreach ($errors as $key => $value) {
                $this->error($key);
            }

            return;
        }

        $this->indices_repo = array_map(function ($index) use ($size, $box_size) {
            return [
                $row = (int) ($index / $size),
                $col = (int) ($index % $size),
                $row - ($row % $box_size),
                $col - ($col % $box_size),
            ];
        }, range(0, $size ** 2 - 1));

        $this->original_nums = range(1, $max_value);
        $empty_board = $this->generateEmpty(size: $size);

        $store = Cache::getStore();

        $total = 0.0;
        $this->info("Caching the Boards...");
        $cache_key = $size . "_" . $box_size . "_" . $max_value . "_";
        $start_time = microtime(true);
        foreach ($this->generate(size: $size, box_size: $box_size, board: $empty_board, columns: $empty_board) as $board) {
            $store->forever(($cache_key . $total++), $board);
            $this->output->write(sprintf("\x1b[%dG", 1));
            $this->output->write("\x1b[2K");
            $this->output->write("<info>Total $size * $size Boards Cached: $total</info>");
        }
        $time = number_format((microtime(true) - $start_time) * 1000, 2, thousands_separator: "");
        $this->info(str_repeat(".", 20) . number_format($time, 2) . "ms");
        $this->info("Time per board: " . number_format($time / (max($total, 1)), 2) . "ms");



        $this->info("Saving them to Database...");
        $chunk_size = 1000;
        $start_time = microtime(true);

        $count = 0.0;
        for ($i = 0; $total > 0; $i++) {
            $chunk = min($chunk_size, $total);
            $start_key = $i * $chunk_size;
            $last_key = $start_key + $chunk;

            $boards = [];
            for ($j = $start_key; $j <= $last_key; $j++) {
                $boards[] = ["board" => implode("", Arr::flatten(Cache::pull($cache_key . $j)))];
            }

            DB::table("string_solutions")->insert($boards);

            $total -= $chunk;
            $count += $chunk;
            $this->output->write(sprintf("\x1b[%dG", 1));
            $this->output->write("\x1b[2K");
            $this->output->write("<info>Total $size * $size Boards Saved: $count</info>");
        }

        $time = number_format((microtime(true) - $start_time) * 1000, 2, thousands_separator: "");
        $this->info(str_repeat(".", 20) . number_format($time, 2) . "ms");
    }

    private function generateEmpty(int $placeholder = 0, int $size = 9)
    {
        return array_fill(0, $size, array_fill(0, $size, $placeholder));
    }

    public function generate(int $size = 9, int $box_size = 3, int $index = 0, array $board = [], array $columns = [])
    {
        [$row, $col, $box_row, $box_col] = $this->indices_repo[$index];

        $nums = array_diff(
            $this->original_nums,
            $board[$row], // Get all elements in the current row before the current cell.
            $columns[$col], // Get all elements in the current column before the current cell.
            ...array_map(fn ($array) => array_slice($array, $box_col, $box_size), array_slice($board, $box_row, $row - $box_row)) // Get all the elements within the same box and before the current element.
        );

        if ($index === ($size ** 2) - 1) {
            foreach ($nums as $num) {
                $board[$row][$col] = $num;
                yield $board;
            }
        } else {
            foreach ($nums as $num) {
                $board[$row][$col] = $num;
                $columns[$col][$row] = $num;
                yield from $this->generate($size, $box_size, $index + 1, $board, $columns);
            }
        }
    }
}
