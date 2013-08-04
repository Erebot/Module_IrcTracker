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

/**
 * \brief
 *      A module that keeps track of users which are
 *      on the same IRC channels as the bot.
 */
class   Erebot_Module_IrcTracker
extends Erebot_Module_Base
{
    /// Maps tokens to normalized nicknames.
    protected $_nicks;

    /// Maps channels to a list of tokens for users present in that channel.
    protected $_chans;

    /// Whether the IRC server supports the UHNAMES extension or not.
    protected $_hasUHNAMES;

    /// Internal Address List, à la mIRC.
    protected $_ial;

    /// Sequence number, incremented by 1 after each new token generation.
    protected $_sequence;


    /// Return the current nickname for some user.
    const INFO_NICK     = 'Nick';

    /// Return the current identity (in the IRC sense) for some user.
    const INFO_IDENT    = 'Ident';

    /// Return the current hostname for some user.
    const INFO_HOST     = 'Host';

    /// Return the current IRC mask for some user.
    const INFO_MASK     = 'Mask';

    /// Return whether the given user is currently connected or not.
    const INFO_ISON     = 'IsOn';


    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     */
    public function _reload($flags)
    {
        if ($this->_channel !== NULL)
            return;

        if ($flags & self::RELOAD_MEMBERS) {
            if ($flags & self::RELOAD_INIT) {
                $this->_chans       = array();
                $this->_ial         = array();
                $this->_hasUHNAMES  = FALSE;
                $this->_nicks       = array();
                $this->_sequence    = 0;
            }
        }

        if ($flags & self::RELOAD_HANDLERS) {
            // Handles some user changing his nickname.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleNick')),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_Nick')
            );
            $this->_connection->addEventHandler($handler);

            // Handles some user joining a channel the bot is on.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleJoin')),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_Join')
            );
            $this->_connection->addEventHandler($handler);

            // Handles some user leaving a channel (for various reasons).
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleLeaving')),
                new Erebot_Event_Match_Any(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Quit'
                    ),
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Part'
                    ),
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Kick'
                    )
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles possible extensions.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleCapabilities')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Event_ServerCapabilities'
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles information received when the bot joins a channel.
            $numeric = new Erebot_NumericHandler(
                new Erebot_Callable(array($this, 'handleNames')),
                $this->getNumRef('RPL_NAMEREPLY')
            );
            $this->_connection->addNumericHandler($numeric);

            $numeric = new Erebot_NumericHandler(
                new Erebot_Callable(array($this, 'handleWho')),
                $this->getNumRef('RPL_WHOREPLY')
            );
            $this->_connection->addNumericHandler($numeric);

            // Handles modes given/taken to/from users on IRC channels.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleChanModeAddition')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Interface_Event_Base_ChanModeGiven'
                )
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleChanModeRemoval')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Interface_Event_Base_ChanModeTaken'
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles users on the WATCH list (see also the WatchList module).
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleNotification')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Event_NotificationAbstract'
                )
            );
            $this->_connection->addEventHandler($handler);
        }
    }

    /**
     * Frees the resources associated with this module.
     */
    protected function _unload()
    {
        foreach ($this->_ial as $entry) {
            if (isset($entry['TIMER']))
                $this->removeTimer($entry['TIMER']);
        }
    }

    /**
     * Given some user's full IRC identity (nick!ident\@host),
     * this methods extracts and returns that user's nickname.
     *
     * \param string $source
     *      Some user's full IRC identity (as "nick!ident\@host").
     *
     * \retval string
     *      The nickname of the user represented by that identity.
     *
     * \note
     *      This method will still work as expected if given
     *      only a nickname to work with. Therefore, it is safe
     *      to call this method with the result of a previous
     *      invocation. Thus, the following snippet:
     *      Erebot_Module_IrcTracker::extractNick(
     *          Erebot_Module_IrcTracker::extractNick('foo!bar\@baz')
     *      );
     *      will return "foo" as expected.
     */
    static public function extractNick($source)
    {
        if (strpos($source, '!') === FALSE)
            return $source;
        return substr($source, 0, strpos($source, '!'));
    }

    /**
     * Updates the IAL with new information on some user.
     *
     * \param string $nick
     *      Some user's nickname whose IAL entry will
     *      be updated.
     *
     * \param string $ident
     *      Some user's identity, in the IRC sense of the term.
     *
     * \param string $host
     *      Some user's hostname.
     */
    protected function _updateUser($nick, $ident, $host)
    {
        $collator   = $this->_connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE) {
            $key = $this->_sequence++;
            $this->_nicks[$key] = $normNick;
        }

        if (isset($this->_ial[$key]['TIMER']))
            $this->removeTimer($this->_ial[$key]['TIMER']);

        if (!isset($this->_ial[$key]) || $this->_ial[$key]['ident'] === NULL) {
            $this->_ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => TRUE,
                'TIMER' => NULL,
            );
            return;
        }

        if ($ident !== NULL) {
            if ($this->_ial[$key]['ident'] != $ident ||
                $this->_ial[$key]['host'] != $host) {
                unset($this->_nicks[$key]);
                unset($this->_ial[$key]);
                $key = $this->_sequence++;
                $this->_nicks[$key] = $normNick;
            }

            $this->_ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => TRUE,
                'TIMER' => NULL,
            );
        }
    }

    /**
     * Removes some user from the IAL.
     *
     * \param string $nick
     *      Nickname of the user that is to be removed
     *      from the IAL.
     */
    protected function _removeUser($nick)
    {
        $collator   = $this->_connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->_nicks);

        if ($key === FALSE)
            return;

        $this->_ial[$key]['TIMER'] = NULL;
        if (!isset($this->_nicks[$key]) || count($this->getCommonChans($nick)))
            return;

        unset($this->_nicks[$key]);
        unset($this->_ial[$key]);
    }

    /**
     * Removes some user from the IAL when the timer
     * associated with their disconnection times out.
     *
     * \param Erebot_Interface_Timer $timer
     *      Timer associated with the user's disconnection.
     *
     * \param string $nick
     *      Nickname of the user to remove from the IAL.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function removeUser(Erebot_Interface_Timer $timer, $nick)
    {
        $this->_removeUser($nick);
    }

    /**
     * Handles a notification about some user
     * (dis)connecting from/to the IRC network.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_Source $event
     *      Notification.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNotification(
        Erebot_Interface_EventHandler       $handler,
        Erebot_Interface_Event_Base_Source  $event
    )
    {
        $user = $event->getSource();
        if ($event instanceof Erebot_Interface_Event_Notify) {
            return $this->_updateUser(
                $user->getNick(),
                $user->getIdent(),
                $user->getHost(Erebot_Interface_Identity::CANON_IPV6)
            );
        }

        if ($event instanceof Erebot_Interface_Event_UnNotify) {
            return $this->_removeUser($user->getNick());
        }
    }

    /**
     * Handles a nick change.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Nick $event
     *      Nick change event.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNick(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Nick     $event
    )
    {
        $oldNick    = (string) $event->getSource();
        $newNick    = (string) $event->getTarget();

        $collator       = $this->_connection->getCollator();
        $normOldNick    = $collator->normalizeNick($oldNick);
        $normNewNick    = $collator->normalizeNick($newNick);
        $key = array_search($normOldNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $this->_removeUser($normNewNick);
        $this->_nicks[$key]         = $normNewNick;
        $this->_ial[$key]['nick']   = $newNick;
    }

    /**
     * Handles some user leaving an IRC channel.
     * This may result from either a QUIT or KICK command.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_Generic $event
     *      An event indicating that some user is leaving
     *      an IRC channel the bot is on.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleLeaving(
        Erebot_Interface_EventHandler       $handler,
        Erebot_Interface_Event_Base_Generic $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Kick)
            $nick = (string) $event->getTarget();
        else
            $nick = (string) $event->getSource();

        $collator   = $this->_connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->_nicks);

        if ($event instanceof Erebot_Interface_Event_Quit) {
            foreach ($this->_chans as $chan => $data) {
                if (isset($data[$key]))
                    unset($this->_chans[$chan][$key]);
            }
        }
        else
            unset($this->_chans[$event->getChan()][$key]);

        if (!count($this->getCommonChans($nick))) {
            $this->_ial[$key]['ison'] = FALSE;
            $delay = $this->parseInt('expire_delay', 60);
            if ($delay < 0)
                $delay = 0;

            if (!$delay) {
                $this->_removeUser($nick);
            }
            else {
                $timerCls       = $this->getFactory('!Timer');
                $callableCls    = $this->getFactory('!Callable');
                $timer = new $timerCls(
                    new $callableCls(array($this, 'removeUser')),
                    $delay,
                    FALSE,
                    array($nick)
                );
                $this->addTimer($timer);
            }
        }
    }

    /**
     * Handles server capabilities.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Event_ServerCapabilities $event
     *      An event referencing a module that can determine
     *      the IRC server's capabilities, such as
     *      Erebot_Module_ServerCapabilities.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleCapabilities(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Event_ServerCapabilities $event
    )
    {
        $module = $event->getModule();
        if ($module->hasExtendedNames())
            $this->sendCommand('PROTOCTL NAMESX');
        if ($module->hasUserHostNames()) {
            $this->sendCommand('PROTOCTL UHNAMES');
            $this->_hasUHNAMES = TRUE;
        }
    }

    /**
     * Handles a list with the nicknames
     * of all users in a given IRC channel.
     *
     * \param Erebot_Interface_NumericHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Numeric $numeric
     *      A numeric event with the nicknames of users
     *      in an IRC channel the bot just joined.
     *      This is the same type of numeric event as
     *      when the NAMES command is issued.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNames(
        Erebot_Interface_NumericHandler $handler,
        Erebot_Interface_Event_Numeric  $numeric
    )
    {
        $text   = $numeric->getText();
        $chan   = $text[1];
        $users  = new Erebot_TextWrapper(
            ltrim($numeric->getText()->getTokens(2), ':')
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

            $identityCls = $this->getFactory('!Identity');
            $identity   = new $identityCls($user);
            $nick       = $identity->getNick();
            $collator   = $this->_connection->getCollator();
            $normNick   = $collator->normalizeNick($nick);

            $this->_updateUser(
                $nick,
                $identity->getIdent(),
                $identity->getHost(Erebot_Interface_Identity::CANON_IPV6)
            );
            $key = array_search($normNick, $this->_nicks);
            $this->_chans[$chan][$key] = $modes;
        }
    }

    /**
     * Handles information about some user.
     *
     * \param Erebot_Interface_NumericHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Numeric $numeric
     *      Numeric event containing some user's nickname,
     *      IRC identity and hostname.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleWho(
        Erebot_Interface_NumericHandler $handler,
        Erebot_Interface_Event_Numeric  $numeric
    )
    {
        $text = $numeric->getText();
        $this->_updateUser($text[4], $text[1], $text[2]);
    }

    /**
     * Handles some user joining an IRC channel
     * the bot is currently on.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Join $event
     *      Event indicating that some user joined
     *      a channel the bot is on.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleJoin(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Join     $event
    )
    {
        $user       = $event->getSource();
        $nick       = $user->getNick();
        $collator   = $this->_connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);

        $this->_updateUser(
            $nick,
            $user->getIdent(),
            $user->getHost(Erebot_Interface_Identity::CANON_IPV6)
        );
        $key = array_search($normNick, $this->_nicks);
        $this->_chans[$event->getChan()][$key] = array();
    }

    /**
     * Handles someone receiving a new status on an IRC channel,
     * for example, when someone is OPped.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_ChanModeGiven $event
     *      Event indicating someone's status changed
     *      on an IRC channel the bot is currently on,
     *      giving that person new privileges.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleChanModeAddition(
        Erebot_Interface_EventHandler               $handler,
        Erebot_Interface_Event_Base_ChanModeGiven   $event
    )
    {
        $user       = $event->getTarget();
        $nick       = self::extractNick($user);
        $collator   = $this->_connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $this->_chans[$event->getChan()][$key][] =
            Erebot_Utils::getVStatic($event, 'MODE_LETTER');
    }

    /**
     * Handles someone losing his status on an IRC channel,
     * for example, when someone is DEOPped.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_ChanModeTaken $event
     *      Event indicating someone's status changed
     *      on an IRC channel the bot is currently on,
     *      removing privileges from that person.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleChanModeRemoval(
        Erebot_Interface_EventHandler               $handler,
        Erebot_Interface_Event_Base_ChanModeTaken   $event
    )
    {
        $user       = $event->getTarget();
        $nick       = self::extractNick($user);
        $collator   = $this->_connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $modeIndex = array_search(
            Erebot_Utils::getVStatic($event, 'MODE_LETTER'),
            $this->_chans[$event->getChan()][$key]
        );
        if ($modeIndex === FALSE)
            return;

        unset($this->_chans[$event->getChan()][$key][$modeIndex]);
    }

    /**
     * Returns a tracking token for some user.
     *
     * \param string $nick
     *      The nickname of some user we want to start tracking.
     *
     * \param string $cls
     *      (optional) Class to use to create the token.
     *      Defaults to Erebot_Module_IrcTracker_Token.
     *
     * \retval mixed
     *      A token that can later be used to return information
     *      on that user (such as his/her nickname, IRC identity,
     *      hostname and whether that person is still online or
     *      not).
     *
     * \throw Erebot_NotFoundException
     *      There is currently no user connected on an IRC channel
     *      the bot is on matching the given nickname.
     */
    public function startTracking(
        $nick,
        $cls    = 'Erebot_Module_IrcTracker_Token'
    )
    {
        $identityCls = $this->getFactory('!Identity');
        $fmt = $this->getFormatter(NULL);
        if ($nick instanceof $identityCls)
            $identity = $nick;
        else {
            if (!is_string($nick)) {
                throw new Erebot_InvalidValueException(
                    $fmt->_('Not a valid nick')
                );
            }
            $identity = new $identityCls($nick);
        }

        $collator   = $this->_connection->getCollator();
        $nick       = $collator->normalizeNick($identity->getNick());
        $key        = array_search($nick, $this->_nicks);

        if ($key === FALSE)
            throw new Erebot_NotFoundException($fmt->_('No such user'));
        return new $cls($this, $key);
    }

    /**
     * Returns information about some user given a token
     * associated with that user.
     *
     * \param opaque $token
     *      Token associated with the user.
     *
     * \param opaque $info
     *      The type of information we're interested in.
     *      This is one of the INFO_* constants provided
     *      by this class.
     *
     * \param array $args
     *      (optional) Additional arguments for the query.
     *      Defaults to an empty array. At present time,
     *      this argument is only useful when you pass
     *      one of Erebot_Module_IrcTracker::INFO_HOST or
     *      Erebot_Module_IrcTracker::INFO_MASK as the
     *      value for $info. In this case, you may pass an
     *      array containing a boolean ($canonical) indicating
     *      the type of hostname canonicalization to apply.
     *      See also Erebot_Interface_Identity::getHost()
     *      for more information on the $canonical parameter.
     *
     * \retval mixed
     *      Requested information about that user.
     *      This may be NULL if the requested information
     *      has not been obtained yet.
     *
     * \throw Erebot_InvalidValueException
     *      The value for $info is invalid.
     *
     * \throw Erebot_NotFoundException
     *      The given $token does not match any known user.
     *
     * \warning
     *      This method is not meant to be called directly.
     *      Instead, you should call the equivalent methods
     *      (getNick(), getHost(), etc.) from the token
     *      returned by Erebot_Module_IrcTracker::startTracking().
     */
    public function getInfo($token, $info, $args = array())
    {
        if ($token instanceof Erebot_Module_IrcTracker_Token) {
            $methods = array(
                self::INFO_ISON     => 'isOn',
                self::INFO_MASK     => 'getMask',
                self::INFO_NICK     => 'getNick',
                self::INFO_IDENT    => 'getIdent',
                self::INFO_HOST     => 'getHost',
            );
            if (!isset($methods[$info]))
                throw new Erebot_InvalidValueException('No such information');
            array_unshift($args, $token, $methods[$info]);
            return call_user_func($args);
        }

        $fmt = $this->getFormatter(NULL);
        if (is_string($token)) {
            $collator   = $this->_connection->getCollator();
            $token      = $collator->normalizeNick(
                self::extractNick($token)
            );
            $token = array_search($token, $this->_nicks);
            if ($token === FALSE) {
                throw new Erebot_NotFoundException(
                    $fmt->_('No such user')
                );
            }
        }

        if (!isset($this->_ial[$token]))
            throw new Erebot_NotFoundException($fmt->_('No such token'));

        $info = strtolower($info);
        if ($info == 'mask') {
            if ($this->_ial[$token]['ident'] === NULL)
                return $this->_ial[$token]['nick'].'!*@*';
            return  $this->_ial[$token]['nick'].'!'.
                    $this->_ial[$token]['ident'].'@'.
                    $this->_ial[$token]['host'];
        }

        if (!array_key_exists($info, $this->_ial[$token])) {
            throw new Erebot_InvalidValueException(
                $fmt->_('No such information')
            );
        }
        return $this->_ial[$token][$info];
    }

    /**
     * Indicates whether some user is present
     * on a given IRC channel.
     *
     * \param string $chan
     *      IRC channel that user must be on.
     *
     * \param mixed $nick
     *      (optional) Either some user's nickname
     *      (a string) or NULL. Defaults to NULL.
     *      When this parameter is NULL, this method
     *      tests whether the bot is on the given
     *      IRC channel or not.
     *
     * \retval bool
     *      TRUE is the given user is on that
     *      IRC channel, FALSE otherwise.
     */
    public function isOn($chan, $nick = NULL)
    {
        if ($nick === NULL)
            return isset($this->_chans[$chan]);

        $nick       = self::extractNick($nick);
        $collator   = $this->_connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            return FALSE;
        return isset($this->_chans[$chan][$key]);
    }

    /**
     * Returns a list of IRC channels the bot and some
     * other user have in common.
     *
     * \param string $nick
     *      Nickname of the user for which we want to know
     *      what channels (s)he shares with the bot.
     *
     * \retval list
     *      A list with the names of all IRC channels
     *      that user and the bot have in common.
     *
     * \throw Erebot_NotFoundException
     *      The given nickname does not match any known user.
     */
    public function getCommonChans($nick)
    {
        $nick       = self::extractNick($nick);
        $collator   = $this->_connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            throw new Erebot_NotFoundException('No such user');

        $results = array();
        foreach ($this->_chans as $chan => $users) {
            if (isset($users[$key]))
                $results[] = $chan;
        }
        return $results;
    }

    /**
     * Returns a list with the masks of all users
     * that match a given (wildcard) mask and are
     * on the given IRC channel.
     *
     * \param string $mask
     *      A wildcard match to use to filter out
     *      users (eg. "*!*@*.fr" to find all users
     *      connected using a french ISP).
     *
     * \param mixed $chan
     *      (optional) Only search for users that
     *      have joined this IRC channel (given
     *      by its name, as a string).
     *      May also be set to NULL to search for
     *      all users known to the bot, no matter
     *      what channels they are currently on.
     *      Defaults to NULL.
     *
     * \retval list
     *      A list with the masks of all users
     *      matching the given criteria.
     *
     * \throw Erebot_NotFoundException
     *      The given channel name does not match
     *      the name of any channel the bot is on.
     */
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
            foreach (array_keys($this->_chans[$chan]) as $key) {
                $entry  = $this->_ial[$key];
                $full   = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
                if (preg_match($pattern, $full) == 1)
                    $results[] = $full;
            }
            return $results;
        }

        foreach ($this->_ial as $entry) {
            $full = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
            if (preg_match($pattern, $full) == 1)
                $results[] = $full;
        }
        return $results;
    }

    /**
     * Returns channel status associated
     * with the given user.
     *
     * \param string $chan
     *      The IRC channel we're interested in.
     *
     * \param string $nick
     *      Nickname of the user whose status on
     *      the given channel we're interested in.
     *
     * \retval list
     *      A list with the status/privileges
     *      for that user on the given channel.
     *      Each status is given by the letter
     *      for the channel mode that refers to
     *      it (eg. "o" for "operator status").
     *
     * \throw Erebot_NotFoundException
     *      The given channel or nickname is not
     *      not known to the bot.
     */
    public function userPrivileges($chan, $nick)
    {
        if (!isset($this->_chans[$chan][$nick]))
            throw new Erebot_NotFoundException('No such channel or user');
        return $this->_chans[$chan][$nick];
    }

    /**
     * Returns a list with the nicknames of all users
     * that (do not) have some specific modes on them
     * on some IRC channel.
     *
     * \param string $chan
     *      The IRC channel we're interested in.
     *
     * \param array $modes
     *      Only return those users that have these
     *      statuses on the given IRC channel.
     *
     * \param bool $negate
     *      Negate the search, ie. only return those
     *      users that DO NOT have the given statuses
     *      on the given IRC channel.
     *
     * \retval list
     *      Nicknames of all users matching the given
     *      criteria.
     *
     * \throw Erebot_NotFoundException
     *      The bot is not present on the given IRC
     *      channel and so the search cannot succeed.
     */
    public function byChannelModes($chan, $modes, $negate = FALSE)
    {
        if (!isset($this->_chans[$chan]))
            throw new Erebot_NotFoundException('No such channel');
        if (!is_array($modes))
            $modes = array($modes);

        $results = array();
        $nbModes = count($modes);
        foreach ($this->_chans[$chan] as $key => $chmodes) {
            if ($nbModes) {
                $commonCount = count(array_intersect($modes, $chmodes));
                if (($commonCount == $nbModes && $negate === FALSE) ||
                    ($commonCount == 0 && $negate === TRUE))
                    $results[] = $this->_nicks[$key];
            }
            else if (((bool) count($chmodes)) == $negate)
                $results[] = $this->_nicks[$key];
        }
        return $results;
    }
}

