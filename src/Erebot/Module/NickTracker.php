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

class   Erebot_Module_NickTracker
extends Erebot_Module_Base
{
    protected $_nicks;

    public function reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            $this->_nicks = array();
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handler = new Erebot_EventHandler(
                array($this, 'handleNick'),
                new Erebot_Event_Match_InstanceOf('Erebot_Event_Nick')
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                array($this, 'handlePartOrQuit'),
                new Erebot_Event_Match_InstanceOf('Erebot_Event_Quit')
            );
            $this->_connection->addEventHandler($handler);
        }
    }

    public function startTracking($nick)
    {
        if (!is_string($nick)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException(
                $translator->gettext('Not a valid nick')
            );
        }

        $this->_nicks[] = $nick;
        end($this->_nicks);
        $key = key($this->_nicks);
        return new Erebot_Module_NickTracker_Token($this, $key);
    }

    public function stopTracking($token)
    {
        if ($token instanceof Erebot_Module_NickTracker_Token)
            return $token->__destruct();

        if (!isset($this->_nicks[$token])) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_NotFoundException(
                $translator->gettext('No such token')
            );
        }
        unset($this->_nicks[$token]);
    }

    public function getNick($token)
    {
        if ($token instanceof Erebot_Module_NickTracker_Token)
            return (string) $token;

        if (!isset($this->_nicks[$token])) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_NotFoundException(
                $translator->gettext('No such token')
            );
        }
        return $this->_nicks[$token];
    }

    public function handleNick(Erebot_Interface_Event_Generic &$event)
    {
        $oldNick        = (string) $event->getSource();
        $newNick        = (string) $event->getTarget();

        foreach ($this->_nicks as $token => &$nick) {
            if (!$this->_connection->irccasecmp($nick, $oldNick))
                $this->_nicks[$token] = $newNick;
        }
        unset($nick);
    }

    public function handlePartOrQuit(Erebot_Interface_Event_Generic &$event)
    {
        $srcNick        = (string) $event->getSource();

        foreach ($this->_nicks as $token => &$nick) {
            if (!$this->_connection->irccasecmp($nick, $srcNick))
                unset($this->_nicks[$token]);
        }
        unset($nick);
    }

    public function handleKick(Erebot_Interface_Event_Generic &$event)
    {
        $srcNick        = (string) $event->getTarget();

        foreach ($this->_nicks as $token => &$nick) {
            if (!$this->_connection->irccasecmp($nick, $srcNick))
                unset($this->_nicks[$token]);
        }
        unset($nick);
    }
}

