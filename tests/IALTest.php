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

class   TrackerHelper
extends Erebot_Module_IrcTracker
{
    public function getNicks()
    {
        return $this->_nicks;
    }

    public function getIAL()
    {
        return $this->_IAL;
    }

    public function getChans()
    {
        return $this->_chans;
    }

    public function updateUser($nick, $ident, $host)
    {
        return $this->_updateUser($nick, $ident, $host);
    }
}

class   IALTest
extends ErebotModuleTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->_networkConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(0));

        $this->_module = new TrackerHelper(NULL);
        $this->_module->reload(
            $this->_connection,
            Erebot_Module_Base::RELOAD_ALL |
            Erebot_Module_Base::RELOAD_INIT
        );
    }

    public function tearDown()
    {
        unset($this->_module);
        parent::tearDown();
    }

    public function testIAL()
    {
        $this->_module->updateUser('nick', NULL, NULL);
        $this->_module->updateUser('nick', 'ident', 'host');

        $this->assertEquals(
            array(
                0 => array(
                    'nick'  => 'nick',
                    'ident' => 'ident',
                    'host'  => 'host',
                    'ison'  => TRUE,
                    'TIMER' => NULL,
                ),
            ),
            $this->_module->getIAL()
        );
    }

    public function testIAL2()
    {
        $this->_module->updateUser('nick', 'ident', 'host');
        $this->_module->updateUser('nick', NULL, NULL);

        $this->assertEquals(
            array(
                0 => array(
                    'nick'  => 'nick',
                    'ident' => 'ident',
                    'host'  => 'host',
                    'ison'  => TRUE,
                    'TIMER' => NULL,
                ),
            ),
            $this->_module->getIAL()
        );
    }

    public function testIAL3()
    {
        $this->_module->updateUser('nick', NULL, NULL);
        $this->_module->updateUser('nick', NULL, NULL);

        $this->assertEquals(
            array(
                0 => array(
                    'nick'  => 'nick',
                    'ident' => NULL,
                    'host'  => NULL,
                    'ison'  => TRUE,
                    'TIMER' => NULL,
                ),
            ),
            $this->_module->getIAL()
        );
    }

    public function testIAL4()
    {
        $this->_module->updateUser('nick', 'ident', 'host');
        $this->_module->updateUser('nick', 'ident', 'host');

        $this->assertEquals(
            array(
                0 => array(
                    'nick'  => 'nick',
                    'ident' => 'ident',
                    'host'  => 'host',
                    'ison'  => TRUE,
                    'TIMER' => NULL,
                ),
            ),
            $this->_module->getIAL()
        );
    }
}

