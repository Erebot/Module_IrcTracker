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
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Nick')->getMock();
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
        $this->_module = new \Erebot\Module\IrcTracker(NULL);
        parent::setUp();

        $this->_networkConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(0));

        $this->_module->reloadModule(
            $this->_connection,
            \Erebot\Module\Base::RELOAD_MEMBERS
        );

        $identity = $this->getMockBuilder('\\Erebot\\Interfaces\\Identity')->getMock();
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

        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Join')->getMock();
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
        $this->_module->unloadModule();
        parent::tearDown();
    }

    /**
     * @covers \Erebot\Module\IrcTracker::extractNick
     */
    public function testExtractNick()
    {
        $extracted = \Erebot\Module\IrcTracker::extractNick('foo!bar@baz.qux');
        $this->assertEquals('foo', $extracted);
        $this->assertEquals(
            $extracted,
            \Erebot\Module\IrcTracker::extractNick($extracted)
        );
    }

    /**
     * @expectedException   \Erebot\NotFoundException
     */
    public function testInvalidToken()
    {
        $this->_module->getInfo(NULL, \Erebot\Module\IrcTracker::INFO_NICK);
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

        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Kick')->getMock();
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

        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Part')->getMock();
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
        $this->assertEquals("???", (string) $token);
    }

    public function testByChannelModes()
    {
        $users = array(
            'Q' => '\\Erebot\\Interfaces\\Event\\Owner',
            'A' => '\\Erebot\\Interfaces\\Event\\Protect',
            'O' => '\\Erebot\\Interfaces\\Event\\Op',
            'H' => '\\Erebot\\Interfaces\\Event\\Halfop',
            'V' => '\\Erebot\\Interfaces\\Event\\Voice',
            'FOO'   => FALSE,
        );

        // Create a few users and give them some power.
        foreach ($users as $user => $cls) {
            if ($cls === FALSE)
                continue;

            $identity = $this->getMockBuilder('\\Erebot\\Interfaces\\Identity')->getMock();
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

            $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Join')->getMock();
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

            $event = $this->getMockBuilder($cls)->getMock();
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
            $modes = array_map('strtolower', $modes);

            $received = $this->_module->byChannelModes('#test', $modes, TRUE);
            sort($expected);
            sort($received);
            $this->assertEquals(
                $expected, $received,
                "Negative search for '".strtolower($user)."'"
            );

            $this->assertEquals(
                array($user),
                $this->_module->byChannelModes('#test', $modes),
                "Positive search for '".strtolower($user)."'"
            );
        }

        // Protect "q".
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Protect')->getMock();
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
            array('Q'),
            $this->_module->byChannelModes('#test', $modes),
            "Positive search for multiple modes"
        );

        // We expect all users except those which are +q/+a.
        $expected = array_diff(
            array_keys($users),
            array_map('strtoupper', $modes)
        );
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

