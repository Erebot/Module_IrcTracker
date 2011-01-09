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

class   IrcTrackerTest
extends ErebotModuleTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->_connection
            ->expects($this->any())
            ->method('getModule')
            ->will($this->returnValue($this));

        $this->_module = new Erebot_Module_IrcTracker(
            $this->_connection,
            NULL
        );
        $this->_module->reload( Erebot_Module_Base::RELOAD_ALL |
                                Erebot_Module_Base::RELOAD_INIT);

        $event = new Erebot_Event_Join(
            $this->_connection,
            '#test',
            'foo!ident@host'
        );
        $this->_module->handleJoin($event);
    }

    public function tearDown()
    {
        unset($this->_module);
        parent::tearDown();
    }

    // Mock ServerCapabilities module.
    public function irccasecmp($a, $b) {
        return strcasecmp($a, $b);
    }

    /**
     * @expectedException   Erebot_NotFoundException
     */
    public function testInvalidToken()
    {
        $this->_module->getInfo(NULL, Erebot_Module_IrcTracker::INFO_NICK);
    }

    public function testTrackingThroughNickChanges()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = new Erebot_Event_Nick($this->_connection, 'foo', 'bar');
        $this->_module->handleNick($event);
        $this->assertEquals("bar", (string) $token);

        $event = new Erebot_Event_Nick($this->_connection, 'foo', 'qux');
        $this->_module->handleNick($event);
        $this->assertEquals("bar", (string) $token);

        $event = new Erebot_Event_Nick($this->_connection, 'bar', 'baz');
        $this->_module->handleNick($event);
        $this->assertEquals("baz", (string) $token);
    }

    public function testTrackingThroughKick()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = new Erebot_Event_Kick($this->_connection, '#test', 'bar', 'foo', 'Doh!');
        $this->_module->handleLeaving($event);
        $this->assertEquals("???", (string) $token);
    }

    public function testTrackingThroughPart()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = new Erebot_Event_Part($this->_connection, '#test', 'foo', 'Doh!');
        $this->_module->handleLeaving($event);
        $this->assertEquals("???", (string) $token);
    }

    public function testTrackingThroughQuit()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = new Erebot_Event_Quit($this->_connection, 'foo', 'Doh!');
        $this->_module->handleLeaving($event);
        $this->assertEquals("???", (string) $token);
    }
}

