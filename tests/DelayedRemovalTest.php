<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once(
    dirname(__FILE__) .
    DIRECTORY_SEPARATOR . 'testenv' .
    DIRECTORY_SEPARATOR . 'bootstrap.php'
);

class   TestToken
extends Erebot_Module_IrcTracker_Token
{
    public function getToken()
    {
        return $this->_token;
    }
}

class   DelayedRemovalTest
extends ErebotModuleTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->_serverConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(10));

        $this->_module = new Erebot_Module_IrcTracker(NULL);
        $this->_module->reload(
            $this->_connection,
            Erebot_Module_Base::RELOAD_ALL |
            Erebot_Module_Base::RELOAD_INIT
        );

        $event = new Erebot_Event_Join(
            $this->_connection,
            '#test',
            'attacker!evil@guy'
        );
        $this->_module->handleJoin($event);

        $event = new Erebot_Event_Join(
            $this->_connection,
            '#test',
            'foo!ident@host'
        );
        $this->_module->handleJoin($event);

        $this->_token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertEquals("foo", (string) $this->_token);

        $event = new Erebot_Event_Quit(
            $this->_connection,
            'foo!ident@host',
            'Quit message'
        );
        $this->_module->handleLeaving($event);

        // The token must not have been invalidated yet.
        $this->assertEquals("foo", (string) $this->_token);
        // but "foo" must have been marked as offline.
        $this->assertEquals(FALSE, $this->_token->isOn());
    }

    public function tearDown()
    {
        unset($this->_module);
        parent::tearDown();
    }

    public function testDelayedRemoval()
    {
        // Simulate the timer going off.
        $this->_module->removeUser(
            new Erebot_Timer(array($this->_module, 'removeUser'), 60, FALSE),
            'foo'
        );

        // "foo" should have been wiped out.
        $this->assertEquals("???", (string) $this->_token);
    }

    public function testClientReconnection()
    {
        $this->assertEquals('foo!ident@host', $this->_token->getMask());

        // Same "foo" reconnects.
        $event = new Erebot_Event_Join(
            $this->_connection,
            '#test',
            'foo!ident@host'
        );
        $this->_module->handleJoin($event);

        // The token must have the same reference.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertEquals($this->_token->getToken(), $token->getToken());
        $this->assertEquals('foo!ident@host', $token->getMask());
        $this->assertEquals('foo!ident@host', $this->_token->getMask());
    }

    public function testHijackByNick()
    {
        $this->assertEquals('foo!ident@host', $this->_token->getMask());

        // Attacker tries to hijack foo's identity
        // by changing his nick into "foo".
        $event = new Erebot_Event_Nick(
            $this->_connection,
            'attacker',
            'foo'
        );
        $this->_module->handleNick($event);

        // The token must have different references.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertNotEquals($this->_token->getToken(), $token->getToken());
        // The mask must reflect the difference.
        $this->assertEquals('foo!evil@guy', $token->getMask());
        // And the old token must have been invalidated.
        $this->assertEquals('???', (string) $this->_token);
    }

    public function testHijackByConnection()
    {
        $this->assertEquals('foo!ident@host', $this->_token->getMask());

        // Attacker reconnects as "foo".
        $event = new Erebot_Event_Join(
            $this->_connection,
            '#test',
            'foo!evil@guy'
        );
        $this->_module->handleJoin($event);

        // The token must have different references.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertNotEquals($this->_token->getToken(), $token->getToken());
        // The mask must reflect the difference.
        $this->assertEquals('foo!evil@guy', $token->getMask());
        // And the old token must have been invalidated.
        $this->assertEquals('???', (string) $this->_token);
    }
}

