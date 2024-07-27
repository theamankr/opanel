<?php

/*
Copyright (c) 2024, Till Brehm, ISPConfig UG
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of ISPConfig nor the names of its contributors
      may be used to endorse or promote products derived from this software without
      specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

class cli {

	private $cmd_opt = array();

	// Add commandline option map
	protected function addCmdOpt($cmd_opt) {
		$this->cmd_opt = $cmd_opt;
	}

	// Get commandline option map
	protected function getCmdOpt() {
		return $this->cmd_opt;
	}

	// Run command module
	public function process($arg) {
		$function = '';
		$opt_string = '';
		$last_arg = 1;
		for($n = 1; $n < count($arg); $n++) {
			$a = ($n > 1) ? $a . ':' . $arg[$n] : $arg[$n];
			if(isset($this->cmd_opt[$a])) {
				$function = $this->cmd_opt[$a];
				$last_arg = $n + 1;
			}
		}

		// Check function name
		if(!preg_match("/[a-z0-9\-]{0,20}/", $function)) die("Invalid commandline option\n");

		// Build new arg array of the remaining arguments
		$new_arg = [];
		if($last_arg < count($arg)) {
			for($n = $last_arg; $n < count($arg); $n++) {
				$new_arg[] = $arg[$n];
			}
		}

		if($function != '') {
			$this->$function($new_arg);
		} else {
			$this->showHelp($new_arg);
			//$this->error("Invalid option");
		}
	}

	// Query function
	public function simple_query($query, $answers, $default, $name = '') {
		global $autoinstall, $autoupdate;
		$finished = false;
		do {
			if($name != '' && isset($autoinstall[$name]) && $autoinstall[$name] != '') {
				if($autoinstall[$name] == 'default') {
					$input = $default;
				} else {
					$input = $autoinstall[$name];
				}
			} elseif($name != '' && isset($autoupdate[$name]) && $autoupdate[$name] != '') {
				if($autoupdate[$name] == 'default') {
					$input = $default;
				} else {
					$input = $autoupdate[$name];
				}
			} else {
				$answers_str = implode(',', $answers);
				$this->swrite($this->lng($query) . ' (' . $answers_str . ') [' . $default . ']: ');
				$input = $this->sread();
			}

			//* Stop the installation
			if($input == 'quit') {
				$this->swriteln($this->lng("Command terminated by user.\n"));
				die();
			}

			//* Select the default
			if($input == '') {
				$answer = $default;
				$finished = true;
			}

			//* Set answer id valid
			if(in_array($input, $answers)) {
				$answer = $input;
				$finished = true;
			}

		} while($finished == false);
		$this->swriteln();
		return $answer;
	}

	public function free_query($query, $default, $name = '') {
		global $autoinstall, $autoupdate;
		if($name != '' && isset($autoinstall[$name]) && $autoinstall[$name] != '') {
			if($autoinstall[$name] == 'default') {
				$input = $default;
			} else {
				$input = $autoinstall[$name];
			}
		} elseif($name != '' && isset($autoupdate[$name]) && $autoupdate[$name] != '') {
			if($autoupdate[$name] == 'default') {
				$input = $default;
			} else {
				$input = $autoupdate[$name];
			}
		} else {
			$this->swrite($this->lng($query) . ' [' . $default . ']: ');
			$input = $this->sread();
		}

		//* Stop the installation
		if($input == 'quit') {
			$this->swriteln($this->lng("Command terminated by user.\n"));
			die();
		}

		$answer = ($input == '') ? $default : $input;
		$this->swriteln();
		return $answer;
	}

	public function lng($text) {
		return $text;
	}

	public function sread() {
		$input = fgets(STDIN);
		return rtrim($input);
	}

	public function swrite($text = '') {
		echo $text;
	}

	public function swriteln($text = '') {
		echo $text . "\n";
	}

	public function error($msg) {
		$this->swriteln($msg);
		die();
	}

	/**
	 * @return bool true when STDOUT is not redirected to a file
	 */
	public function isStdOutATty() {
		return defined('STDOUT') && function_exists('posix_isatty') && posix_isatty(constant('STDOUT'));
	}

	/**
	 * Return the width of the string $text (not counting ANSI escape codes).
	 *
	 * @param string $text
	 * @return int
	 */
	public function stringWidth($text) {
		return mb_strwidth(preg_replace("/\033\[.*?m/", '', $text), 'utf8');
	}

	/**
	 * Formats text so that it is not longer than $width.
	 *
	 * ANSI escape codes do not count to the line length and when a text needs to be wrapped, the currently active
	 * ANSI escape codes get reset before inserting the newline character. This is needed to wrap text in multiple columns.
	 *
	 * @param string $text unwrapped text. May already contain newlines that get preserved.
	 * @param int $width maximum line width
	 * @return string
	 */
	public function wrapText($text, $width = 80) {
		$characters = [];
		$cur_width = 0;
		$in_ansi = false;
		$prev_ansi = '';
		$current_ansi = '';
		$reset_ansi = "\033[0m";
		$input = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		foreach($input as $index => $char) {
			if($char == "\n") {
				$prev_ansi = '';
				$cur_width = 0;
				if($current_ansi) {
					$characters[] = $reset_ansi . "\n" . $current_ansi;
				} else {
					$characters[] = "\n";
				}
			} elseif($in_ansi || $char == "\033") {
				$characters[] = $char;
				if($char == "\033") {
					$prev_ansi = $prev_ansi . $current_ansi;
					$current_ansi = '';
				}
				$current_ansi .= $char;
				$in_ansi = $char != 'm';
				if(!$in_ansi) {
					if($current_ansi == $reset_ansi) {
						$current_ansi = '';
						$prev_ansi = '';
					} else {
						$current_ansi = $prev_ansi . $current_ansi;
					}
				}
			} elseif($char == ' ') {
				// look ahead $width characters and search for a newline or another space
				$prev_ansi = '';
				$lookahead_length = 0;
				$has_better_break = false;
				$lookup_reached_end = false;
				$lookahead_in_ansi = false;
				for($i = $index + 1; $i < count($input); $i++) {
					$next_char = $input[$i];
					$lookup_reached_end = $i == count($input) - 1;
					if($next_char == "\n") {
						$has_better_break = $cur_width + $lookahead_length < $width;
						break;
					} elseif($lookahead_in_ansi || $next_char == "\033") {
						$lookahead_in_ansi = $char != 'm';
					} elseif($next_char == ' ' && $cur_width + $lookahead_length < $width) {
						$has_better_break = true;
						break;
					} else {
						$lookahead_length += mb_strwidth($next_char, 'utf8');
					}
					if($cur_width + $lookahead_length > $width) {
						break;
					}
				}
				if(!$lookup_reached_end && !$has_better_break) {
					$cur_width = 0;
					if($current_ansi) {
						$characters[] = $reset_ansi . "\n" . $current_ansi;
					} else {
						$characters[] = "\n";
					}
				} else {
					$characters[] = $char;
					$cur_width += 1;
				}
			} else {
				$prev_ansi = '';
				$char_width = mb_strwidth($char, 'utf8');
				if($cur_width + $char_width > $width) {
					$cur_width = 0;
					if($current_ansi) {
						$characters[] = $reset_ansi . "\n" . $current_ansi;
					} else {
						$characters[] = "\n";
					}
				}
				$characters[] = $char;
				$cur_width += $char_width;
			}
		}
		return join('', $characters);
	}

	/**
	 * Divides $value up into $count parts. Returns an array with $count integers.
	 * The sum of all returned integers is always $value (Even when rounding happens).
	 *
	 * @param int $value
	 * @param int $count
	 * @return int[]
	 */
	private function getDiscreetDistribution($value, $count) {
		$per_item = intval(floor($value / $count));
		$distribution = array_fill(0, $count, $per_item);
		// add 1 to the columns until the column sum is equal to $value
		$sum = $per_item * $count;
		for($i = 0; $i < $count && $sum < $value; $i++) {
			$distribution[$i] += 1;
			$sum += 1;
		}
		return $distribution;
	}

	/**
	 * Outputs an array of arrays as table to STDOUT. All cells should already be strings. You can use ANSI escape code sequences in the cells.
	 *
	 * Simple table:
	 * <code>
	 *     $this->outputTable([['one', 'two'], ['1', '2']]);
	 * </code>
	 *
	 * You can define minimum column lengths:
	 * <code>
	 *     $this->outputTable([['one', 'two'], ['1', '2']], ['min_lengths' => [10, 10]]);
	 * </code>
	 *
	 * The table tries to size itself to fit in the terminal window width. As default all column width 5 or wider will shrink,
	 * but you can also specify which column(s) should shrink:
	 * <code>
	 *        $this->outputTable([
	 *                ['variable', 'fixed', 'variable'],
	 *                [str_repeat('this is a long column ', 40), '2', str_repeat('another long column ', 60)]
	 *            ], ['variable_columns' => [0,2]]);
	 * </code>
	 *
	 * Small tables do not fill the available terminal window width by default.
	 * You can change this with the `expand` $option:
	 * <code>
	 *        $this->outputTable([['one', 'two'], ['1', '2']], ['expand' => true]);
	 * </code>
	 *
	 * @param array $table array of arrays (rows -> columns) to display as table.
	 * @param array{min_lengths: array|null, variable_columns: array|string|int|null, expand: bool}|null $options optional formatting options
	 * @return void
	 */
	public function outputTable($table, $options = null) {
		if(empty($table)) {
			$this->swriteln('No data to display');
			return;
		}
		if(!is_array($options)) {
			$options = [];
		}
		if(!is_array($options['min_lengths'])) {
			$options['min_lengths'] = [];
		}

		// process input $table
		$columns = [];
		$rows = [];
		$num_columns = false;
		foreach($table as $row) {
			$c = count($row);
			if(!$c || $num_columns !== false && $c != $num_columns) {
				$this->error("every input row of outputTable input needs to the same size (not null)");
			}
			$num_columns = $c;
			$r = [];
			$index = 0;
			foreach($row as $value) {
				$value_as_string = (string)$value;
				$max = 0;
				foreach(explode("\n", $value_as_string) as $line) {
					$len = $this->stringWidth($line);
					$max = max($max, max($options['min_lengths'][$index] ?: 0, $len));
				}
				if(!isset($columns[$index])) {
					$columns[$index] = ['length' => 0, 'first' => $index == 0, 'last' => $index == $num_columns - 1];
				}
				if($columns[$index]['length'] < $max) {
					$columns[$index]['length'] = $max;
				}
				$r[$index] = $value_as_string;
				$index += 1;
			}
			$rows[] = $r;
		}

		// fit table to terminal
		if($this->isStdOutATty()) {
			if(!isset($options['variable_columns']) || $options['variable_columns'] == 'all') {
				$options['variable_columns'] = range(0, $num_columns - 1);
			} elseif(!is_array($options['variable_columns'])) {
				$options['variable_columns'] = explode(',', (string)$options['variable_columns']);
			}
			$minimum_variable_column_width = 5;
			$shrink_variable_columns = array_values(array_filter($options['variable_columns'], function($index) use ($minimum_variable_column_width, $columns, $num_columns) {
				return $index < $num_columns && $columns[$index]['length'] >= $minimum_variable_column_width;
			}));
			$expand_variable_columns = array_values(array_filter($options['variable_columns'], function($index) use ($num_columns) {
				return $index < $num_columns;
			}));
			$terminal_width = intval(trim(exec("tput cols") ?: '')) ?: 80;
			$table_width = array_reduce($columns, function($sum, $column) {
				return $sum + $column['length'] + 3;
			}, 1);
			if(count($shrink_variable_columns) > 0 && $table_width > $terminal_width) {
				$diff = $table_width - $terminal_width;
				$diff_per_column = $this->getDiscreetDistribution($diff, count($shrink_variable_columns));
				foreach($shrink_variable_columns as $i => $index) {
					$new_length = $columns[$index]['length'] - $diff_per_column[$i];
					$columns[$index]['length'] = max($minimum_variable_column_width, $new_length);
				}
			} elseif(count($expand_variable_columns) > 0 && $options['expand'] && $table_width < $terminal_width) {
				$diff = $terminal_width - $table_width;
				$diff_per_column = $this->getDiscreetDistribution($diff, count($expand_variable_columns));
				foreach($expand_variable_columns as $i => $index) {
					$new_length = $columns[$index]['length'] + $diff_per_column[$i];
					$columns[$index]['length'] = $new_length;
				}
			}
		}

		// output table
		$separator = function($first_start, $mid_start, $mid, $mid_end, $last_end) use ($columns) {
			$this->swriteln(array_reduce($columns, function($line, $column) use ($first_start, $mid_start, $mid, $mid_end, $last_end) {
				return $line . ($column['first'] ? $first_start : $mid_start) . str_repeat($mid, $column['length']) . ($column['last'] ? $last_end : $mid_end);
			}, ''));
		};
		foreach($rows as $row_index => $row) {
			if($row_index == 0) {
				$separator('╔═', '╤═', '═', '═', '═╗');
			}
			// first pass -> re-wrap lines and get max number of lines of this row
			$height = 1;
			$lines = [];
			foreach($columns as $index => $column) {
				$lines[$index] = explode("\n", $this->wrapText($row[$index], $column['length']));
				$height = max($height, count($lines[$index]));
			}
			// second pass -> output row, line by line
			for($inner = 0; $inner < $height; $inner++) {
				$line = "";
				foreach($columns as $index => $column) {
					$value = $lines[$index][$inner] ?: '';
					$value .= str_repeat(' ', $column['length'] - $this->stringWidth($value));
					$line .= ($column['first'] ? '║ ' : '│ ') . $value . ($column['last'] ? ' ║' : ' ');
				}
				$this->swriteln($line);
			}
			if($row_index < count($rows) - 1) {
				$separator('╟─', '┼─', '─', '─', '─╢');
			} else {
				$separator('╚═', '╧═', '═', '═', '═╝');
			}
		}
	}
}
