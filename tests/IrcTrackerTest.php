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

class   IrcTrackerTest
extends Erebot_Testenv_Module_TestCase
{
    protected function _mockNick($oldnick, $newnick)
    {
        $event = $this->getMock(
            'Erebot_Interface_Event_Nick',
            array(), array(), '', FALSE, FALSE
        );

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue($oldnick));
        $event
            ->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue($newnick));
        return $event;
    }

    public function setUp()
    {
        $this->_module = new Erebot_Module_IrcTracker(NULL);
        parent::setUp();

        $this->_networkConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(0));

        $this->_module->reload(
            $this->_connection,
            Erebot_Module_Base::RELOAD_MEMBERS
        );

        $identity = $this->getMock(
            'Erebot_Interface_Identity',
            array(), array(), '', FALSE, FALSE
        );
        $identity
            ->expects($this->any())
            ->method('getNick')
            ->will($this->returnValue('foo'));
        $identity
            ->expects($this->any())
            ->method('getIdent')
            ->will($this->returnValue('ident'));
        $identity
            ->expects($this->any())
            ->method('getHost')
            ->will($this->returnValue('host'));

        $event = $this->getMock(
            'Erebot_Interface_Event_Join',
            array(), array(), '', FALSE, FALSE
        );
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
        $this->_module->handleJoin($this->_eventHandler, $event);
    }

    public function tearDown()
    {
        $this->_module->unload();
        parent::tearDown();
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

        $event = $this->_mockNick('foo', 'bar');
        $this->_module->handleNick($this->_eventHandler, $event);
        $this->assertEquals("bar", (string) $token);

        $event = $this->_mockNick('foo', 'qux');
        $this->_module->handleNick($this->_eventHandler, $event);
        $this->assertEquals("bar", (string) $token);

        $event = $this->_mockNick('bar', 'baz');
        $this->_module->handleNick($this->_eventHandler, $event);
        $this->assertEquals("baz", (string) $token);
    }

    public function testTrackingThroughKick()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = $this->getMock(
            'Erebot_Interface_Event_Kick',
            array(), array(), '', FALSE, FALSE
        );

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue('foo'));
        $event
            ->expects($this->any())
            ->method('getChan')
            ->will($this->returnValue('#test'));

        $this->_module->handleLeaving($this->_eventHandler, $event);
        $this->assertEquals("???", (string) $token);
    }

    public function testTrackingThroughPart()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = $this->getMock(
            'Erebot_Interface_Event_Part',
            array(), array(), '', FALSE, FALSE
        );

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('foo'));
        $event
            ->expects($this->any())
            ->method('getChan')
            ->will($this->returnValue('#test'));

        $this->_module->handleLeaving($this->_eventHandler, $event);
        $this->assertEquals("???", (string) $token);
    }

    public function testTrackingThroughQuit()
    {
        $token = $this->_module->startTracking('foo');
        $this->assertEquals("foo", (string) $token);

        $event = $this->getMock(
            'Erebot_Interface_Event_Quit',
            array(), array(), '', FALSE, FALSE
        );

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('foo'));

        $this->_module->handleLeaving($this->_eventHandler, $event);
        $this->assertEquals("???", (string) $token);
    }

    public function testByChannelModes()
    {
        $users = array(
            'q' => 'Erebot_Interface_Event_Owner',
            'a' => 'Erebot_Interface_Event_Protect',
            'o' => 'Erebot_Interface_Event_Op',
            'h' => 'Erebot_Interface_Event_Halfop',
            'v' => 'Erebot_Interface_Event_Voice',
            'foo'   => FALSE,
        );

        // Create a few users and give them some power.
        foreach ($users as $user => $cls) {
            if ($cls === FALSE)
                continue;

            $identity = $this->getMock(
                'Erebot_Interface_Identity',
                array(), array(), '', FALSE, FALSE
            );
            $identity
                ->expects($this->any())
                ->method('getNick')
                ->will($this->returnValue($user));
            $identity
                ->expects($this->any())
                ->method('getIdent')
                ->will($this->returnValue('ident'));
            $identity
                ->expects($this->any())
                ->method('getHost')
                ->will($this->returnValue('host'));

            $event = $this->getMock(
                'Erebot_Interface_Event_Join',
                array(), array(), '', FALSE, FALSE
            );
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

            $this->_module->handleJoin($this->_eventHandler, $event);

            $event = $this->getMock(
                $cls,
                array(), array(), '', FALSE, FALSE
            );
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
                ->will($this->returnValue('foo'));
            $event
                ->expects($this->any())
                ->method('getTarget')
                ->will($this->returnValue($user));
            $this->_module->handleChanModeAddition($this->_eventHandler, $event);
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
        $event = $this->getMock(
            'Erebot_Interface_Event_Protect',
            array(), array(), '', FALSE, FALSE
        );
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
            ->will($this->returnValue('foo'));
        $event
            ->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue('q'));
        $this->_module->handleChanModeAddition($this->_eventHandler, $event);

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

    public function testIsOn()
    {
        // The bot is on #test, so this is supposedly TRUE.
        $this->assertEquals(TRUE, $this->_module->isOn('#test'));

        // "foo" is on #test too, so this is also TRUE.
        $this->assertEquals(TRUE, $this->_module->isOn('#test', 'foo'));

        // But "bar" is not, so this is FALSE.
        $this->assertEquals(FALSE, $this->_module->isOn('#test', 'bar'));

        // Last but not least, the bot is not on #strike,
        // so this is also FALSE.
        $this->assertEquals(FALSE, $this->_module->isOn('#strike'));
    }
}

