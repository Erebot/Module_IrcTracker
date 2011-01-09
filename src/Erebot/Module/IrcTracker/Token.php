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

class   Erebot_Module_IrcTracker_Token
{
    protected $_tracker;
    protected $_token;

    public function __construct(Erebot_Module_IrcTracker &$tracker, $token)
    {
        $this->_tracker =&  $tracker;
        $this->_token   =   $token;
    }

    public function getNick()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_NICK
        );
    }

    public function getIdent()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_IDENT
        );
    }

    public function getHost()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_HOST
        );
    }

    public function getMask()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_MASK
        );
    }

    public function __toString()
    {
        try {
            return $this->getNick();
        }
        catch (Erebot_NotFoundException $e) {
            return "???";
        }
    }
}

