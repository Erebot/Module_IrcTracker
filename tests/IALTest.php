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

class   TrackerHelper
extends \Erebot\Module\IrcTracker
{
    public function getNicks()
    {
        return $this->nicks;
    }

    public function getIAL()
    {
        return $this->ial;
    }

    public function getChans()
    {
        return $this->chans;
    }

    public function publicUpdateUser($nick, $ident, $host)
    {
        return $this->updateUser($nick, $ident, $host);
    }
}

class   IALTest
extends Erebot_Testenv_Module_TestCase
{
    public function setUp()
    {
        $this->_module = new TrackerHelper(NULL);
        parent::setUp();

        $this->_networkConfig
            ->expects($this->any())
            ->method('parseInt')
            ->will($this->returnValue(0));

        $this->_module->reloadModule(
            $this->_connection,
            \Erebot\Module\Base::RELOAD_MEMBERS
        );
    }

    public function tearDown()
    {
        $this->_module->unloadModule();
        parent::tearDown();
    }

    public function testIAL()
    {
        $this->_module->publicUpdateUser('nick', NULL, NULL);
        $this->_module->publicUpdateUser('nick', 'ident', 'host');

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
        $this->_module->publicUpdateUser('nick', 'ident', 'host');
        $this->_module->publicUpdateUser('nick', NULL, NULL);

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
        $this->_module->publicUpdateUser('nick', NULL, NULL);
        $this->_module->publicUpdateUser('nick', NULL, NULL);

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
        $this->_module->publicUpdateUser('nick', 'ident', 'host');
        $this->_module->publicUpdateUser('nick', 'ident', 'host');

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

