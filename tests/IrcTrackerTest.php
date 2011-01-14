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

    public function testByChannelModes()
    {
        $users = array(
            'q' => 'Erebot_Event_Owner',
            'a' => 'Erebot_Event_Protect',
            'o' => 'Erebot_Event_Op',
            'h' => 'Erebot_Event_Halfop',
            'v' => 'Erebot_Event_Voice',
            'foo'   => FALSE,
        );

        // Create a few users and give them some power.
        foreach ($users as $user => $cls) {
            if ($cls === FALSE)
                continue;

            $event = new Erebot_Event_Join(
                $this->_connection,
                '#test',
                $user.'!ident@host'
            );
            $this->_module->handleJoin($event);

            $event = new $cls(
                $this->_connection,
                '#test', 'foo', $user
            );
            $this->_module->handleChanModeAddition($event);
        }

        foreach ($users as $user => $cls) {
            $modes = array($user);
            $expected = array_diff(array_keys($users), $modes);
            if ($cls === FALSE)
                $modes = array();

            $received = $this->_module->byChannelModes('#test', $modes, TRUE);
            sort($expected);
            sort($received);
            $this->assertEquals(
                $expected, $received,
                "Negative search for '$user'"
            );

            $this->assertEquals(
                array($user),
                $this->_module->byChannelModes('#test', $modes),
                "Positive search for '$user'"
            );
        }

        // Protect "q".
        $event = new Erebot_Event_Protect(
            $this->_connection,
            '#test', 'foo', 'q'
        );
        $this->_module->handleChanModeAddition($event);

        // We expect only "q" to be returned
        // as it is now +qa.
        $modes = array('q', 'a');
        $this->assertEquals(
            array('q'),
            $this->_module->byChannelModes('#test', $modes),
            "Positive search for multiple modes"
        );

        // We expect all users except those which are +q/+a.
        $expected = array_diff(array_keys($users), $modes);
        $received = $this->_module->byChannelModes('#test', $modes, TRUE);
        sort($expected);
        sort($received);
        $this->assertEquals(
            $expected, $received,
            "Negative search for multiple modes"
        );
    }
}

