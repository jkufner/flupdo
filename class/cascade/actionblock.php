<?php
/*
 * Copyright (c) 2013, Josef Kufner  <jk@frozen-doe.net>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Flupdo\Cascade;

use Flupdo\Machine\AbstractMachine;

/**
 * Universal implemntation of state machine action invocation. Inputs are
 * passed as arguments to the transition, returned value is set on one or more
 * outputs.
 */
class ActionBlock extends \Block
{

	protected $inputs = array(
		'*' => null,
	);

	protected $outputs = array(
		'*' => true,
		'done' => true,
	);

	const force_exec = true;

	protected $machine;
	protected $action;
	protected $output_values;

	/**
	 * Setup block to act as expected. Configuration is done by Flupdo
	 * Block Storage.
	 */
	public function __construct($machine, $action, $action_desc)
	{
		$this->machine = $machine;
		$this->action = $action;

		// get block description (block is not created unless this is defined)
		$block_desc = $action_desc['block'];

		// define inputs
		if (!is_array($block_desc['inputs'])) {
			throw new \RuntimeException('Inputs are not specified in block configuration.');
		}
		$this->inputs = $block_desc['inputs'];

		// define outputs
		if (!is_array($block_desc['outputs'])) {
			throw new \RuntimeException('Outputs are not specified in block configuration.');
		}
		$this->output_values = $block_desc['outputs'];
		$this->outputs = array_combine(array_keys($this->output_values), array_pad(array(), count($this->output_values), true));
		$this->outputs['done'] = true;
	}


	public function main()
	{
		$args = $this->inAll();

		// get ID if specified
		if (array_key_exists('id', $args)) {
			$id = $args['id'];
			unset($args['id']);
		} else {
			$id = null;
		}

		// invoke transition
		// TODO: Handle exceptions
		$result = $this->machine->invokeTransition($id, $action, $args, $returns);

		// interpret return value
		switch ($returns) {
			case AbstractMachine::RETURNS_VALUE:
				break;
			case AbstractMachine::RETURNS_NEW_ID:
				$id = $result;
			default:
				throw new \RuntimeException('Unknown semantics of the return value: '.$returns);
		}

		// set outputs
		foreach ($this->output_values as $output => $out_value) {
			switch ($out_value) {
				case 'id':
					$this->out($output, $id);
					break;
				case 'return_value':
					$this->out($output, $result);
					break;
				case 'properties':
					$this->out($output, $this->machine->getProperties($id));
					break;
				case 'state':
					$this->out($output, $this->machine->getState($id));
					break;
			}
		}

		$this->out('done', true);
	}

}

