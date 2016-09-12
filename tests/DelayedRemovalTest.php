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

class   TestToken
extends \Erebot\Module\IrcTracker\Token
{
    public function getToken()
    {
        return $this->token;
    }
}

class   DelayedRemovalTest
extends Erebot_Testenv_Module_TestCase
{
    protected function _mockJoin($nick, $ident, $host)
    {
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Join')->getMock();
        $identity = $this->getMockBuilder('\\Erebot\\Interfaces\\Identity')->getMock();
        $identity
            ->expects($this->any())
            ->method('getNick')
            ->will($this->returnValue($nick));
        $identity
            ->expects($this->any())
            ->method('getIdent')
            ->will($this->returnValue($ident));
        $identity
            ->expects($this->any())
            ->method('getHost')
            ->will($this->returnValue($host));

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getChan')
            ->will($this->returnValue('#test'));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue($identity));
        return $event;
    }

    public function setUp()
    {
        $this->_module = new \Erebot\Module\IrcTracker(NULL);
        parent::setUp();

        $timer = $this->getMockBuilder('\\Erebot\\TimerInterface')->getMock();
        $this->_module->setFactory('!Timer', get_class($timer));

        $this->_serverConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(10));

        $this->_module->reloadModule(
            $this->_connection,
            \Erebot\Module\Base::RELOAD_MEMBERS
        );

        $event = $this->_mockJoin('attacker', 'evil', 'guy');
        $this->_module->handleJoin($this->_eventHandler, $event);

        $event = $this->_mockJoin('foo', 'ident', 'host');
        $this->_module->handleJoin($this->_eventHandler, $event);

        $this->_token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertEquals("foo", (string) $this->_token);

        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Quit')->getMock();
        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('foo'));
        $this->_module->handleLeaving($this->_eventHandler, $event);

        // The token must not have been invalidated yet.
        $this->assertEquals("foo", (string) $this->_token);
        // but "foo" must have been marked as offline.
        $this->assertEquals(FALSE, $this->_token->isOn());
    }

    public function tearDown()
    {
        $this->_module->unloadModule();
        parent::tearDown();
    }

    public function testDelayedRemoval()
    {
        // Simulate the timer going off.
        $timer = $this->getMockBuilder('\\Erebot\\TimerInterface')->getMock();
        $this->_module->removeUser($timer, 'foo');

        // "foo" should have been wiped out.
        $this->assertEquals("???", (string) $this->_token);
    }

    public function testClientReconnection()
    {
        $this->assertEquals(
            'foo!ident@host',
            $this->_token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );

        // Same "foo" reconnects.
        $event = $this->_mockJoin('foo', 'ident', 'host');
        $this->_module->handleJoin($this->_eventHandler, $event);

        // The token must have the same reference.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertEquals($this->_token->getToken(), $token->getToken());
        $this->assertEquals(
            'foo!ident@host',
            $token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );
        $this->assertEquals(
            'foo!ident@host',
            $this->_token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );
    }

    public function testHijackByNick()
    {
        $this->assertEquals(
            'foo!ident@host',
            $this->_token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );

        // Attacker tries to hijack foo's identity
        // by changing his nick into "foo".
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Nick')->getMock();
        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('attacker'));
        $event
            ->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue('foo'));

        $this->_module->handleNick($this->_eventHandler, $event);

        // The token must have different references.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertNotEquals($this->_token->getToken(), $token->getToken());
        // The mask must reflect the difference.
        $this->assertEquals(
            'foo!evil@guy',
            $token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );
        // And the old token must have been invalidated.
        $this->assertEquals('???', (string) $this->_token);
    }

    public function testHijackByConnection()
    {
        $this->assertEquals(
            'foo!ident@host',
            $this->_token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );

        // Attacker reconnects as "foo".
        $event = $this->_mockJoin('foo', 'evil', 'guy');
        $this->_module->handleJoin($this->_eventHandler, $event);

        // The token must have different references.
        $token = $this->_module->startTracking('foo', 'TestToken');
        $this->assertNotEquals($this->_token->getToken(), $token->getToken());
        // The mask must reflect the difference.
        $this->assertEquals(
            'foo!evil@guy',
            $token->getMask(\Erebot\Interfaces\Identity::CANON_IPV6)
        );
        // And the old token must have been invalidated.
        $this->assertEquals('???', (string) $this->_token);
    }
}

