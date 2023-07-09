<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SudokuGeneratorSessionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sudoku:session {--size=9} {--box_size=3} {--y|yes} {--o|no-output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts a session for the Generator of the puzzles.';

    public function __construct(
        public $size = 9,
        public $box_size = 3,
        public $boxes = 9,
        public $start_time = 0,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->size = (int) $this->option("size");
        $this->box_size = (int) $this->option("box_size");
        $ignore_confirmation = $this->option("yes");
        $no_output = $this->option("no-output")
            ? function () {
            }
            : function ($board, $time) {
                $this->printBoard($board);
                $this->line("Generated in " . $time . "ms");
            };

        if ($this->size % $this->box_size !== 0) {
            $this->error("Size should be a multiple of box size.");
            return;
        }

        if ((int) sqrt($this->size) < $this->box_size) {
            $this->error("Box size should be less than the square root of the size.");
            return;
        }

        $this->boxes = (int) $this->size / $this->box_size;
        $generator = $this->generate(size: $this->size, box_size: $this->box_size);

        $this->info("Sudoku session started.");

        $total = 0;
        $command_start_time = microtime(true);
        while ($generator->current() && $generator->valid() && ($ignore_confirmation || $this->confirm("Print one?", true))) {
            $this->startTimer();
            $board = $generator->current();
            $generator->next();

            $no_output($board, $this->getRunTime($this->start_time));
            $total++;
        }
        $time = $this->getRunTime($command_start_time);

        $this->info("Sudoku session ended.");
        $this->info("Total combinations: " . $total);
        $this->info("Total time: " . $time . "ms");
        $this->info("Average time: " . $time / max($total, 1) . "ms");
    }

    public function printBoard(array $board)
    {
        $board_print = [];

        $cell_space = strlen((string) $this->size);
        $boundary_placeholder = str_repeat("+" . str_repeat("-", ($this->box_size * $cell_space) + $this->box_size + 1), $this->boxes) . "+";
        foreach ($board as $row_index => $row) {
            if (in_array($row_index, range(0, $this->size - 1, $this->box_size), true)) {
                $board_print[] = $boundary_placeholder . "\n";
            }

            foreach ($board[$row_index] as $col_index => $cell) {
                if (in_array($col_index, range(0, $this->size - 1, $this->box_size), true)) {
                    $board_print[] = "|";
                }

                $board_print[] = $cell . str_repeat(" ", $cell_space - strlen((string) $cell));
            }

            $board_print[] = "|\n";
        }

        $board_print[] = $boundary_placeholder;

        $this->line(str_replace("\n ", "\n", implode(" ", $board_print)));
    }

    private function generateEmpty(int $placeholder = 0, int $size = 9)
    {
        return array_fill(0, $size, array_fill(0, $size, $placeholder));
    }

    public function startTimer()
    {
        $this->start_time = microtime(true);
    }

    public function getRunTime($start_time = 0)
    {
        return ((float) number_format((microtime(true) - $start_time) * 1000000)) / 1000;
    }

    public function generate(array $board = [], int $size = 9, int $box_size = 3)
    {
        $board = $board ?: $this->generateEmpty(size: $size);
        $original_nums = range(1, $size);
        $index = 0;

        $update_variables = function ($board, $original_nums, $size, $box_size, $index) {
            [$row, $col] = [(int) ($index / $size), $index % $size];
            [$box_row, $box_col] = [$row - ($row % $box_size), $col - ($col % $box_size)];

            $nums = array_values(
                array_diff(
                    array_filter($original_nums, function ($item) use ($board, $row, $col) {
                        return $item > $board[$row][$col];
                    }),
                    array_slice($board[$row], 0, $col), // Get all elements in the current row before the current cell.
                    array_map(fn ($array) => $array[$col], array_slice($board, 0, $row)), // Get all elements in the current column before the current cell.
                    ...array_map(fn ($array) => array_slice($array, $box_col, $box_size), array_slice($board, $box_row, $row - $box_row)) // Get all the elements within the same box and before the current element.
                )
            );

            return [$row, $col, $nums];
        };

        while (true) {
            [$row, $col, $nums] = $update_variables($board, $original_nums, $size, $box_size, $index);

            while (count($nums) === 0) {
                if ($index !== 0) {
                    $board[$row][$col] = 0;
                }

                $index--;

                if ($index === -1) {
                    return null;
                }

                [$row, $col, $nums] = $update_variables($board, $original_nums, $size, $box_size, $index);
            }

            if ($this->getRunTime($this->start_time) > 30) {
                return false;
            }

            $board[$row][$col] = $nums[0];

            if ($row === $size - 1 && $col === $size - 1) {
                yield $board;
                continue;
            }

            $index++;
        }
    }
}
