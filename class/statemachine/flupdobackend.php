<?php
/*
 * Copyright (c) 2013, Josef Kufner  <jk@frozen-doe.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. Neither the name of the author nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE REGENTS AND CONTRIBUTORS ``AS IS'' AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED.  IN NO EVENT SHALL THE REGENTS OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
 * OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
 * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
 * SUCH DAMAGE.
 */

namespace Flupdo\StateMachine;

use	\Flupdo\Flupdo\Flupdo,
	\Flupdo\Flupdo\FlupdoProxy;

/**
 * SmallDB Backend which uses database via Flupdo as storage.
 */
class FlupdoBackend extends AbstractBackend
{
	private $flupdo;

	/**
	 * Static table of known machine types. Inherit this class and replace this
	 * table, or use 'machine_types' option when creating this backend.
	 *
	 * Each record is also passed to AbstractMachine::initializeMachine().
	 */
	protected $machine_type_table = array(
		/* '$machine_name' => array(
		 *     'table' => '$database_table',
		 *     'class' => '\SomeNamespace\Class',
		 *  	// Human-friendly name of the type
		 *  	'name' => 'Foo Bar',
		 *  	// Human-friendly description (one short paragraph, plain text)
		 *  	'desc' => 'Lorem ipsum dolor sit amet, ...',
		 *  	// Name of the file containing full machine definition
		 *  	'src'  => 'example/foo.json',
		 */
	);


	public function __construct($alias, $options)
	{
		parent::__construct($alias, $options);

		// Use, wrap or create database connection
		if (isset($options['flupdo'])) {
			$this->flupdo = $options['flupdo'];
			if (!($this->flupdo instanceof Flupdo || $this->flupdo instanceof FlupdoProxy)) {
				throw new \InvalidArgumentException('The "flupdo" option must contain an instance of Flupdo class.');
			}
		} else if (isset($options['pdo'])) {
			if (!($options['pdo'] instanceof \PDO)) {
				throw new \InvalidArgumentException('The "pdo" option must contain an instance of PDO class.');
			}
			$this->flupdo = new FlupdoProxy($options['pdo']);
		} else {
			$this->flupdo = new Flupdo($options['dsn'], @ $options['username'], @ $options['password'], @ $options['driver_options']);
		}

		// Machine type table
		if (isset($options['machine_types'])) {
			$this->machine_type_table = $options['machine_types'];
		}
	}


	public function getFlupdo()
	{
		return $this->flupdo;
	}


	public function createQueryBuilder($type)
	{
		return $this->getMachine($type)->createQueryBuilder();
	}


	/**
	 * Get all known state machine types.
	 *
	 * Returns array of strings.
	 */
	public function getKnownTypes()
	{
		return array_keys($this->machine_type_table);
	}


	/**
	 * Describe given type without creating an instance of related state
	 * machine. Intended as data source for user interface generators
	 * (menu, navigation, ...).
	 *
	 * Returns machine description as propery-value pairs in array. There
	 * are few well-known property names which should be used if possible.
	 * Any unknown properties will be ignored.
	 *
	 * array(
	 *  	// Human-friendly name of the type
	 *  	'name' => 'Foo Bar',
	 *  	// Human-friendly description (one short paragraph, plain text)
	 *  	'desc' => 'Lorem ipsum dolor sit amet, ...',
	 *  	// Name of the file containing full machine definition
	 *  	'src'  => 'example/foo.json',
	 *  	...
	 * )
	 */
	public function describeType($type)
	{
		return @ $this->machine_type_table[$type];
	}


	/**
	 * Infer type of referenced machine type using lookup table.
	 *
	 * Reference is pair: Table name + Primary key.
	 *
	 * Returns true if decoding is successful, $type and $id are set to
	 * decoded values. Otherwise returns false.
	 *
	 * Since references are global identifier, this method identifies the
	 * type of referenced machine. In simple cases it maps part of ref to
	 * type, in more complex scenarios it may ask database.
	 *
	 * In simple applications ref consists of pair $type and $id, where $id
	 * is uniquie within given $type.
	 *
	 * $aref is array of arguments passed to AbstractBackend::ref() or
	 * single literal if only one argument was passed.
	 *
	 * $type is string.
	 *
	 * $id is literal or array of literals (in case of compound key).
	 */
	public function inferMachineType($aref, & $type, & $id)
	{
		$len = count($aref);

		if ($len == 0) {
			return false;
		}

		$type = array_shift($aref);

		if (!isset($this->machine_type_table[$type])) {
			return false;
		}

		if ($len > 2) {
			$id = $aref;
		} else {
			list($id) = $aref;
		}

		return true;
	}


	/**
	 * Factory method: Prepare state machine of given type - a model shared
	 * between multiple real statemachines stored in backend. Do not forget
	 * that actual machine is not reachable, you only get this interface.
	 *
	 * This creates only implementation of the machine, not concrete
	 * instance. See AbstractMachine.
	 *
	 * Returns descendant of AbstractMachine or null.
	 */
	protected function createMachine($type)
	{
		$desc = @ $this->machine_type_table[$type];
		if ($desc === null || empty($desc['class'])) {
			return null;
		}

		return new $desc['class']($this, $type, $desc);
	}

}
