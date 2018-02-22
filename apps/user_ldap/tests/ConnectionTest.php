<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Jarkko Lehtoranta <devel@jlranta.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\User_LDAP\Tests;
use OCA\User_LDAP\Connection;
use OCA\User_LDAP\ILDAPWrapper;

/**
 * Class Test_Connection
 *
 * @group DB
 *
 * @package OCA\User_LDAP\Tests
 */
class ConnectionTest extends \Test\TestCase {
	/** @var \OCA\User_LDAP\ILDAPWrapper  */
	protected $ldap;

	/** @var  Connection */
	protected $connection;

	public function setUp() {
		parent::setUp();

		$this->ldap       = $this->createMock(ILDAPWrapper::class);
		// we use a mock here to replace the cache mechanism, due to missing DI in LDAP backend.
		$this->connection = $this->getMockBuilder('OCA\User_LDAP\Connection')
			->setMethods(['getFromCache', 'writeToCache'])
			->setConstructorArgs([$this->ldap, '', null])
			->getMock();

		$this->ldap->expects($this->any())
			->method('areLDAPFunctionsAvailable')
			->will($this->returnValue(true));
	}

	public function testOriginalAgentUnchangedOnClone() {
		//background: upon login a bind is done with the user credentials
		//which is valid for the whole LDAP resource. It needs to be reset
		//to the agent's credentials
		$lw  = $this->createMock(ILDAPWrapper::class);

		$connection = new Connection($lw, '', null);
		$agent = array(
			'ldapAgentName' => 'agent',
			'ldapAgentPassword' => '123456',
		);
		$connection->setConfiguration($agent);

		$testConnection = clone $connection;
		$user = array(
			'ldapAgentName' => 'user',
			'ldapAgentPassword' => 'password',
		);
		$testConnection->setConfiguration($user);

		$agentName = $connection->ldapAgentName;
		$agentPawd = $connection->ldapAgentPassword;

		$this->assertSame($agentName, $agent['ldapAgentName']);
		$this->assertSame($agentPawd, $agent['ldapAgentPassword']);
	}

	public function testUseBackupServer() {
		$mainHost = 'ldap://nixda.ldap';
		$backupHost = 'ldap://fallback.ldap';
		$config = [
			'ldapConfigurationActive' => true,
			'ldapHost' => $mainHost,
			'ldapPort' => 389,
			'ldapBackupHost' => $backupHost,
			'ldapBackupPort' => 389,
			'ldapAgentName' => 'uid=agent',
			'ldapAgentPassword' => 'SuchASecret'
		];

		$this->connection->setIgnoreValidation(true);
		$this->connection->setConfiguration($config);

		$this->ldap->expects($this->any())
			->method('isResource')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('setOption')
			->will($this->returnValue(true));

		$this->ldap->expects($this->exactly(3))
			->method('connect')
			->will($this->returnValue('ldapResource'));

		$this->ldap->expects($this->any())
			->method('errno')
			->will($this->returnValue(0));

		// Not called often enough? Then, the fallback to the backup server is broken.
		$this->connection->expects($this->exactly(4))
			->method('getFromCache')
			->with('overrideMainServer')
			->will($this->onConsecutiveCalls(false, false, true, true));

		$this->connection->expects($this->once())
			->method('writeToCache')
			->with('overrideMainServer', true);

		$isThrown = false;
		$this->ldap->expects($this->exactly(3))
			->method('bind')
			->will($this->returnCallback(function () use (&$isThrown) {
				if(!$isThrown) {
					$isThrown = true;
					throw new \OC\ServerNotAvailableException();
				}
				return true;
			}));

		$this->connection->init();
		$this->connection->resetConnectionResource();
		// with the second init() we test whether caching works
		$this->connection->init();
	}

	public function testBindWithInvalidCredentials() {
		// background: Bind with invalid credentials should return false
		// and not throw a ServerNotAvailableException.

		$host = 'ldap://nixda.ldap';
		$config = [
			'ldapConfigurationActive' => true,
			'ldapHost' => $host,
			'ldapPort' => 389,
			'ldapBackupHost' => '',
			'ldapAgentName' => 'user',
			'ldapAgentPassword' => 'password'
		];

		$this->connection->setIgnoreValidation(true);
		$this->connection->setConfiguration($config);

		$this->ldap->expects($this->any())
			->method('isResource')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('setOption')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('connect')
			->will($this->returnValue('ldapResource'));

		$this->ldap->expects($this->once())
			->method('bind')
			->will($this->returnValue(false));

		// LDAP_INVALID_CREDENTIALS
		$this->ldap->expects($this->any())
			->method('errno')
			->will($this->returnValue(0x31));

		try {
			$this->assertFalse($this->connection->bind(), 'Connection::bind() should not return true with invalid credentials.');
		} catch (\OC\ServerNotAvailableException $e) {
			$this->fail('Failed asserting that exception of type "OC\ServerNotAvailableException" is not thrown.');
		}
	}

	public function testStartTlsNegotiationFailure() {
		// background: If Start TLS negotiation fails,
		// a ServerNotAvailableException should be thrown.

		$host = 'ldap://nixda.ldap';
		$port = 389;
		$config = [
			'ldapConfigurationActive' => true,
			'ldapHost' => $host,
			'ldapPort' => $port,
			'ldapTLS' => true,
			'ldapBackupHost' => '',
			'ldapAgentName' => 'user',
			'ldapAgentPassword' => 'password'
		];

		$this->connection->setIgnoreValidation(true);
		$this->connection->setConfiguration($config);

		$this->ldap->expects($this->any())
			->method('isResource')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('connect')
			->will($this->returnValue('ldapResource'));

		$this->ldap->expects($this->any())
			->method('setOption')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('bind')
			->will($this->returnValue(true));

		$this->ldap->expects($this->any())
			->method('errno')
			->will($this->returnValue(0));

		$this->ldap->expects($this->any())
			->method('startTls')
			->will($this->returnValue(false));

		$this->expectException(\OC\ServerNotAvailableException::class);
		$this->expectExceptionMessage('Start TLS failed, when connecting to LDAP host ' . $host . '.');

		$this->connection->init();
	}

}
