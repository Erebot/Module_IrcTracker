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

class   Erebot_Module_IrcTracker
extends Erebot_Module_Base
{
    # @TODO: method to find users by their channel modes
    static protected $_metadata = array(
        'requires'  =>  array(
            'Erebot_Module_ServerCapabilities',
        ),
    );
    protected $_nicks;
    protected $_chans;
    protected $_hasUHNAMES;
    protected $_IAL;
    protected $_sequence;

    const INFO_NICK     = 'Nick';
    const INFO_IDENT    = 'Ident';
    const INFO_HOST     = 'Host';
    const INFO_MASK     = 'Mask';

    public function reload($flags)
    {
        if ($this->_channel !== NULL)
            return;

        if ($flags & self::RELOAD_MEMBERS) {
            if ($flags & self::RELOAD_INIT) {
                $this->_chans = array();
                $this->_IAL = array();
                $this->_hasUHNAMES = FALSE;
                $this->_nicks = array();
                $this->_sequence = 0;
            }
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handler = new Erebot_EventHandler(
                array($this, 'handleNick'),
                new Erebot_Event_Match_InstanceOf('Erebot_Event_Nick')
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                array($this, 'handleJoin'),
                new Erebot_Event_Match_InstanceOf('Erebot_Event_Join')
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                array($this, 'handleLeaving'),
                new Erebot_Event_Match_Any(
                    new Erebot_Event_Match_InstanceOf('Erebot_Event_Quit'),
                    new Erebot_Event_Match_InstanceOf('Erebot_Event_Part'),
                    new Erebot_Event_Match_InstanceOf('Erebot_Event_Kick')
                )
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                array($this, 'handleCapabilities'),
                new Erebot_Event_Match_InstanceOf('Erebot_Event_ServerCapabilities')
            );
            $this->_connection->addEventHandler($handler);

            $raw = new Erebot_RawHandler(
                array($this, 'handleNames'),
                Erebot_Interface_Event_Raw::RPL_NAMEREPLY
            );
            $this->_connection->addRawHandler($raw);

            $raw = new Erebot_RawHandler(
                array($this, 'handleWho'),
                Erebot_Interface_Event_Raw::RPL_WHOREPLY
            );
            $this->_connection->addRawHandler($raw);

            $handler = new Erebot_EventHandler(
                array($this, 'handleChanModeAddition'),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_ChanModeGiven')
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                array($this, 'handleChanModeRemoval'),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_ChanModeTaken')
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

        $nick = Erebot_Utils::extractNick(
            $this->_connection->normalizeNick($nick)
        );
        $key = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            throw new Erebot_NotFoundException('No such user');
        return new Erebot_Module_IrcTracker_Token($this, $key);
    }

    public function getInfo($token, $info)
    {
        if ($token instanceof Erebot_Module_IrcTracker_Token)
            return call_user_func(array($token, 'get'.$info));

        $translator = $this->getTranslator(NULL);
        if (is_string($token)) {
            $token = $this->_connection->normalizeNick(
                Erebot_Utils::extractNick($token)
            );
            $token = array_search($token, $this->_nicks);
            if ($token === FALSE) {
                throw new Erebot_NotFoundException(
                    $translator->gettext('No such user')
                );
            }
        }

        if (!isset($this->_IAL[$token])) {
            throw new Erebot_NotFoundException(
                $translator->gettext('No such token')
            );
        }

        $info = strtolower($info);
        if ($info == 'mask') {
            if ($this->_IAL[$token]['ident'] === NULL)
                return $this->_IAL[$token]['nick'].'!*@*';
            return  $this->_IAL[$token]['nick'].'!'.
                    $this->_IAL[$token]['ident'].'@'.
                    $this->_IAL[$token]['host'];
        }

        if (!isset($this->_IAL[$token][$info])) {
            throw new Erebot_NotFoundException(
                $translator->gettext('No such information')
            );
        }
        return $this->_IAL[$token][$info];
    }

    public function handleNick(Erebot_Interface_Event_Generic &$event)
    {
        $oldNick        = (string) $event->getSource();
        $newNick        = (string) $event->getTarget();

        $normOldNick = $this->_connection->normalizeNick($oldNick);
        $normNewNick = $this->_connection->normalizeNick($newNick);
        $key = array_search($normOldNick, $this->_nicks);
        if ($key === FALSE)
            return;
        $this->_nicks[$key]         = $normNewNick;
        $this->_IAL[$key]['nick']   = $newNick;
    }

    public function handleLeaving(Erebot_Interface_Event_Generic &$event)
    {
        if ($event instanceof Erebot_Event_Kick)
            $nick = (string) $event->getTarget();
        else
            $nick = (string) $event->getSource();

        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);

        if ($event instanceof Erebot_Event_Quit) {
            foreach ($this->_chans as $chan => $data) {
                if (isset($data[$key]))
                    unset($this->_chans[$chan][$key]);
            }
        }
        else
            unset($this->_chans[$event->getChan()][$key]);

        if (!count($this->getCommonChans($nick))) {
            unset($this->_nicks[$key]);
            unset($this->_IAL[$key]);
        }
    }

    public function handleCapabilities(Erebot_Event_ServerCapabilities $event)
    {
        $module = $event->getModule();
        if ($module->hasExtendedNames())
            $this->sendCommand('PROTOCTL NAMESX');
        if ($module->hasUserHostNames()) {
            $this->sendCommand('PROTOCTL UHNAMES');
            $this->_hasUHNAMES = TRUE;
        }
    }

    public function handleNames(Erebot_Interface_Event_Raw $raw)
    {
        $text   = $raw->getText();
        $chan   = $text[1];
        $users  = new Erebot_TextWrapper(
            ltrim($raw->getText()->getTokens(2), ':')
        );

        try {
            $caps = $this->_connection->getModule(
                'Erebot_Module_ServerCapabilities'
            );
        }
        catch (Erebot_NotFoundException $e) {
            return;
        }

        if (!$this->_hasUHNAMES) {
            $this->sendCommand('WHO '.$chan);
        }

        foreach ($users as $user) {
            $modes = array();
            for ($i = 0, $len = strlen($user); $i < $len; $i++) {
                try {
                    $modes[] = $caps->getChanModeForPrefix($user[$i]);
                }
                catch (Erebot_NotFoundException $e) {
                    break;
                }
            }

            $user = substr($user, count($modes));
            if ($user === FALSE)
                continue;

            $nick       = Erebot_Utils::extractNick($user);
            $normNick   = $this->_connection->normalizeNick($nick);
            $key        = array_search($normNick, $this->_nicks);

            // Add it to to the list of known nicks
            // if it's not already present.
            // Also, try to populate the IAL.
            if ($key === FALSE) {
                $key = $this->_sequence++;
                $this->_nicks[$key] = $normNick;
            }

            $ident  = NULL;
            $host   = NULL;
            $pos    = strpos($user, '!');
            if ($pos !== FALSE) {
                $parts  = explode('@', substr($user, $pos));
                assert('count($parts) == 2 /* Invalid mask */');
                $ident  = $parts[0];
                $host   = $parts[1];
            }

            if (!isset($this->_IAL[$key]) ||
                ($this->_IAL[$key]['ident'] === NULL && $ident !== NULL)) {
                $this->_IAL[$key] = array(
                    'nick'  => $nick,
                    'ident' => $ident,
                    'host'  => $host,
                );
            }

            $this->_chans[$chan][$key] = $modes;
        }
    }

    public function handleWho(Erebot_Interface_Event_Raw $raw)
    {
        $text       = $raw->getText();
        $normNick   = $this->_connection->normalizeNick($text[4]);
        $key        = array_search($normNick, $this->_nicks);

        // Add it to to the list of known nicks
        // if it's not already present.
        // Also, try to populate the IAL.
        if ($key === FALSE) {
            $key = $this->_sequence++;
            $this->_nicks[$key] = $normNick;
        }

        if (!isset($this->_IAL[$key]) || $this->_IAL[$key]['ident'] === NULL) {
            $this->_IAL[$key] = array(
                'nick'  => $text[4],
                'ident' => $text[1],
                'host'  => $text[2],
            );
        }
    }

    public function handleJoin(Erebot_Interface_Event_Generic $event)
    {
        $user       = $event->getSource();
        $nick       = Erebot_Utils::extractNick($user);
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);

        // Add it to to the list of known nicks
        // if it's not already present.
        if ($key === FALSE) {
            $key = $this->_sequence++;
            $this->_nicks[$key] = $normNick;
        }

        // Try to populate the IAL.
        $ident  = NULL;
        $host   = NULL;
        $pos    = strpos($user, '!');
        if ($pos !== FALSE) {
            $parts  = explode('@', substr($user, $pos));
            assert('count($parts) == 2 /* Invalid mask */');
            $ident  = $parts[0];
            $host   = $parts[1];
        }

        if (!isset($this->_IAL[$key]) ||
            ($this->_IAL[$key] === NULL && $ident !== NULL)) {
            $this->_IAL[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
            );
        }

        $this->_chans[$event->getChan()][$key] = array();
    }

    public function handleChanModeAddition(Erebot_Interface_Event_ChanModeGiven $event)
    {
        $user       = $event->getSource();
        $nick       = Erebot_Utils::extractNick($user);
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $this->_chans[$event->getChan()][$key][] = $event->MODE_LETTER;
    }

    public function handleChanModeRemoval(Erebot_Interface_Event_ChanModeTaken $event)
    {
        $user       = $event->getSource();
        $nick       = Erebot_Utils::extractNick($user);
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $key2       = array_search(
            $event->MODE_LETTER,
            $this->_chans[$event->getChan()][$key]
        );
        if ($key2 === FALSE)
            return;

        unset($this->_chans[$event->getChan()][$key][$key2]);
    }

    public function getCommonChans($nick)
    {
        $nick   = Erebot_Utils::extractNick($nick);
        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            throw new Erebot_NotFoundException('No such user');

        $results = array();
        foreach ($this->_chans as $chan => $users) {
            if (isset($users[$key]))
                $results[] = $chan;
        }
        return $results;
    }

    public function IAL($mask, $chan = NULL)
    {
        $results = array();

        if (strpos($mask, '!') === FALSE)
            $mask .= '!*@*';
        else if (strpos($mask, '@') === FALSE)
            $mask .= '@*';

        $translationTable = array(
            '\\*'   => '.*',
            '\\?'   => '.',
        );
        $pattern = "#^".strtr(preg_quote($mask, '#'), $translationTable)."$#";

        if ($chan !== NULL) {
            if (!isset($this->_chans[$chan]))
                throw new Erebot_NotFoundException(
                    'The bot is not on that channel!'
                );

            // Search only matching users on that channel.
            foreach ($this->_chans[$chan] as $key) {
                $entry  = $this->_IAL[$key];
                $full   = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
                if (preg_match($pattern, $full) == 1)
                    $results[] = $full;
            }
            return $results;
        }

        foreach ($this->_IAL as $entry) {
            $full = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
            if (preg_match($pattern, $full) == 1)
                $results[] = $full;
        }
        return $results;
    }

    public function userPriviledges($chan, $nick)
    {
        if (!isset($this->_chans[$chan][$nick]))
            throw new Erebot_NotFoundException('No such channel or user');
        return $this->_chans[$chan][$nick];
    }
}

