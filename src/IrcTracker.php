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

namespace Erebot\Module;

/**
 * \brief
 *      A module that keeps track of users which are
 *      on the same IRC channels as the bot.
 */
class IrcTracker extends \Erebot\Module\Base implements \Erebot\Interfaces\HelpEnabled
{
    /// Maps tokens to normalized nicknames.
    protected $nicks;

    /// Maps channels to a list of tokens for users present in that channel.
    protected $chans;

    /// Whether the IRC server supports the UHNAMES extension or not.
    protected $hasUHNAMES;

    /// Internal Address List, à la mIRC.
    protected $ial;

    /// Sequence number, incremented by 1 after each new token generation.
    protected $sequence;


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
     *      A bitwise OR of the Erebot::Module::Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     */
    public function reload($flags)
    {
        if ($this->channel !== null) {
            return;
        }

        if ($flags & self::RELOAD_MEMBERS) {
            if ($flags & self::RELOAD_INIT) {
                $this->chans        = array();
                $this->ial          = array();
                $this->hasUHNAMES   = false;
                $this->nicks        = array();
                $this->sequence     = 0;
            }
        }

        if ($flags & self::RELOAD_HANDLERS) {
            // Handles some user changing his nickname.
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleNick')),
                new \Erebot\Event\Match\Type('\\Erebot\\Interfaces\\Event\\Nick')
            );
            $this->connection->addEventHandler($handler);

            // Handles some user joining a channel the bot is on.
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleJoin')),
                new \Erebot\Event\Match\Type('\\Erebot\\Interfaces\\Event\\Join')
            );
            $this->connection->addEventHandler($handler);

            // Handles some user leaving a channel (for various reasons).
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleLeaving')),
                new \Erebot\Event\Match\Any(
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Quit'
                    ),
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Part'
                    ),
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Kick'
                    )
                )
            );
            $this->connection->addEventHandler($handler);

            // Handles possible extensions.
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleCapabilities')),
                new \Erebot\Event\Match\Type(
                    '\\Erebot\\Event\\ServerCapabilities'
                )
            );
            $this->connection->addEventHandler($handler);

            // Handles information received when the bot joins a channel.
            $numeric = new \Erebot\NumericHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleNames')),
                $this->getNumRef('RPL_NAMEREPLY')
            );
            $this->connection->addNumericHandler($numeric);

            $numeric = new \Erebot\NumericHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleWho')),
                $this->getNumRef('RPL_WHOREPLY')
            );
            $this->connection->addNumericHandler($numeric);

            // Handles modes given/taken to/from users on IRC channels.
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleChanModeAddition')),
                \Erebot\Event\Match\Type(
                    '\\Erebot\\Interfaces\\Event\\Base\\ChanModeGiven'
                )
            );
            $this->connection->addEventHandler($handler);

            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleChanModeRemoval')),
                new \Erebot\Event\Match\Type(
                    '\\Erebot\\Interfaces\\Event\\Base\\ChanModeTaken'
                )
            );
            $this->connection->addEventHandler($handler);

            // Handles users on the WATCH list (see also the WatchList module).
            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleNotification')),
                new \Erebot\Event\Match\Type(
                    '\\Erebot\\Event\\NotificationAbstract'
                )
            );
            $this->connection->addEventHandler($handler);
        }
    }

    /**
     * Frees the resources associated with this module.
     */
    protected function unload()
    {
        foreach ($this->ial as $entry) {
            if (isset($entry['TIMER'])) {
                $this->removeTimer($entry['TIMER']);
            }
        }
    }

    public function getHelp(
        \Erebot\Interfaces\Event\Base\TextMessage   $event,
        \Erebot\Interfaces\TextWrapper              $words
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $fmt        = $this->getFormatter($chan);
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $fmt->_(
                "This module does not provide any command, but ".
                "provides a way for other modules to keep track ".
                "of IRC users through nick changes, disconnections ".
                "and so on."
            );
            $this->sendMessage($target, $msg);
            return true;
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
     *      Erebot::Module::IrcTracker::extractNick(
     *          Erebot::Module::IrcTracker::extractNick('foo!bar\@baz')
     *      );
     *      will return "foo" as expected.
     */
    public static function extractNick($source)
    {
        if (strpos($source, '!') === false) {
            return $source;
        }
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
    protected function updateUser($nick, $ident, $host)
    {
        $collator   = $this->connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->nicks);
        if ($key === false) {
            $key = $this->sequence++;
            $this->nicks[$key] = $normNick;
        }

        if (isset($this->ial[$key]['TIMER'])) {
            $this->removeTimer($this->ial[$key]['TIMER']);
        }

        if (!isset($this->ial[$key]) || $this->ial[$key]['ident'] === null) {
            $this->ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => true,
                'TIMER' => null,
            );
            return;
        }

        if ($ident !== null) {
            if ($this->ial[$key]['ident'] != $ident ||
                $this->ial[$key]['host'] != $host) {
                unset($this->nicks[$key]);
                unset($this->ial[$key]);
                $key = $this->sequence++;
                $this->nicks[$key] = $normNick;
            }

            $this->ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => true,
                'TIMER' => null,
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
    protected function realRemoveUser($nick)
    {
        $collator   = $this->connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->nicks);

        if ($key === false) {
            return;
        }

        $this->ial[$key]['TIMER'] = null;
        if (!isset($this->nicks[$key]) || count($this->getCommonChans($nick))) {
            return;
        }

        unset($this->nicks[$key]);
        unset($this->ial[$key]);
    }

    /**
     * Removes some user from the IAL when the timer
     * associated with their disconnection times out.
     *
     * \param Erebot::TimerInterface $timer
     *      Timer associated with the user's disconnection.
     *
     * \param string $nick
     *      Nickname of the user to remove from the IAL.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function removeUser(\Erebot\TimerInterface $timer, $nick)
    {
        $this->realRemoveUser($nick);
    }

    /**
     * Handles a notification about some user
     * (dis)connecting from/to the IRC network.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::Source $event
     *      Notification.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNotification(
        \Erebot\Interfaces\EventHandler         $handler,
        \Erebot\Interfaces\Event\Base\Source    $event
    ) {
        $user = $event->getSource();
        if ($event instanceof \Erebot\Interfaces\Event\Notify) {
            return $this->updateUser(
                $user->getNick(),
                $user->getIdent(),
                $user->getHost(\Erebot\Interfaces\Identity::CANON_IPV6)
            );
        }

        if ($event instanceof \Erebot\Interfaces\Event\UnNotify) {
            return $this->realRemoveUser($user->getNick());
        }
    }

    /**
     * Handles a nick change.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Nick $event
     *      Nick change event.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNick(
        \Erebot\Interfaces\EventHandler $handler,
        \Erebot\Interfaces\Event\Nick   $event
    ) {
        $oldNick    = (string) $event->getSource();
        $newNick    = (string) $event->getTarget();

        $collator       = $this->connection->getCollator();
        $normOldNick    = $collator->normalizeNick($oldNick);
        $normNewNick    = $collator->normalizeNick($newNick);
        $key = array_search($normOldNick, $this->nicks);
        if ($key === false) {
            return;
        }

        $this->realRemoveUser($normNewNick);
        $this->nicks[$key]         = $normNewNick;
        $this->ial[$key]['nick']   = $newNick;
    }

    /**
     * Handles some user leaving an IRC channel.
     * This may result from either a QUIT or KICK command.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::Generic $event
     *      An event indicating that some user is leaving
     *      an IRC channel the bot is on.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleLeaving(
        \Erebot\Interfaces\EventHandler         $handler,
        \Erebot\Interfaces\Event\Base\Generic   $event
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Kick) {
            $nick = (string) $event->getTarget();
        } else {
            $nick = (string) $event->getSource();
        }

        $collator   = $this->connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->nicks);

        if ($event instanceof \Erebot\Interfaces\Event\Quit) {
            foreach ($this->chans as $chan => $data) {
                if (isset($data[$key])) {
                    unset($this->chans[$chan][$key]);
                }
            }
        } else {
            unset($this->chans[$event->getChan()][$key]);
        }

        if (!count($this->getCommonChans($nick))) {
            $this->ial[$key]['ison'] = false;
            $delay = $this->parseInt('expire_delay', 60);
            if ($delay < 0) {
                $delay = 0;
            }

            if (!$delay) {
                $this->realRemoveUser($nick);
            } else {
                $timerCls       = $this->getFactory('!Timer');
                $timer = new $timerCls(
                    \Erebot\CallableWrapper::wrap(array($this, 'removeUser')),
                    $delay,
                    false,
                    array($nick)
                );
                $this->addTimer($timer);
            }
        }
    }

    /**
     * Handles server capabilities.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Event::ServerCapabilities $event
     *      An event referencing a module that can determine
     *      the IRC server's capabilities, such as
     *      Erebot::Module::ServerCapabilities.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleCapabilities(
        \Erebot\Interfaces\EventHandler     $handler,
        \Erebot\Event\ServerCapabilities    $event
    ) {
        $module = $event->getModule();
        if ($module->hasExtendedNames()) {
            $this->sendCommand('PROTOCTL NAMESX');
        }
        if ($module->hasUserHostNames()) {
            $this->sendCommand('PROTOCTL UHNAMES');
            $this->hasUHNAMES = true;
        }
    }

    /**
     * Handles a list with the nicknames
     * of all users in a given IRC channel.
     *
     * \param Erebot::Interfaces::NumericHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Numeric $numeric
     *      A numeric event with the nicknames of users
     *      in an IRC channel the bot just joined.
     *      This is the same type of numeric event as
     *      when the NAMES command is issued.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleNames(
        \Erebot\Interfaces\NumericHandler   $handler,
        \Erebot\Interfaces\Event\Numeric    $numeric
    ) {
        $text   = $numeric->getText();
        $chan   = $text[1];
        $users  = new \Erebot\TextWrapper(
            ltrim($numeric->getText()->getTokens(2), ':')
        );

        try {
            $caps = $this->connection->getModule(
                '\\Erebot\\Module\\ServerCapabilities'
            );
        } catch (\Erebot\NotFoundException $e) {
            return;
        }

        if (!$this->hasUHNAMES) {
            $this->sendCommand('WHO '.$chan);
        }

        foreach ($users as $user) {
            $modes = array();
            for ($i = 0, $len = strlen($user); $i < $len; $i++) {
                try {
                    $modes[] = $caps->getChanModeForPrefix($user[$i]);
                } catch (\Erebot\NotFoundException $e) {
                    break;
                }
            }

            $user = substr($user, count($modes));
            if ($user === false) {
                continue;
            }

            $identityCls = $this->getFactory('!Identity');
            $identity   = new $identityCls($user);
            $nick       = $identity->getNick();
            $collator   = $this->connection->getCollator();
            $normNick   = $collator->normalizeNick($nick);

            $this->updateUser(
                $nick,
                $identity->getIdent(),
                $identity->getHost(\Erebot\Interfaces\Identity::CANON_IPV6)
            );
            $key = array_search($normNick, $this->nicks);
            $this->chans[$chan][$key] = $modes;
        }
    }

    /**
     * Handles information about some user.
     *
     * \param Erebot::Interfaces::NumericHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Numeric $numeric
     *      Numeric event containing some user's nickname,
     *      IRC identity and hostname.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleWho(
        \Erebot\Interfaces\NumericHandler   $handler,
        \Erebot\Interfaces\Event\Numeric    $numeric
    ) {
        $text = $numeric->getText();
        $this->updateUser($text[4], $text[1], $text[2]);
    }

    /**
     * Handles some user joining an IRC channel
     * the bot is currently on.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Join $event
     *      Event indicating that some user joined
     *      a channel the bot is on.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleJoin(
        \Erebot\Interfaces\EventHandler $handler,
        \Erebot\Interfaces\Event\Join   $event
    ) {
        $user       = $event->getSource();
        $nick       = $user->getNick();
        $collator   = $this->connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);

        $this->updateUser(
            $nick,
            $user->getIdent(),
            $user->getHost(\Erebot\Interfaces\Identity::CANON_IPV6)
        );
        $key = array_search($normNick, $this->nicks);
        $this->chans[$event->getChan()][$key] = array();
    }

    /**
     * Handles someone receiving a new status on an IRC channel,
     * for example, when someone is OPped.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::ChanModeGiven $event
     *      Event indicating someone's status changed
     *      on an IRC channel the bot is currently on,
     *      giving that person new privileges.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleChanModeAddition(
        \Erebot\Interfaces\EventHandler             $handler,
        \Erebot\Interfaces\Event\Base\ChanModeGiven $event
    ) {
        $user       = $event->getTarget();
        $nick       = self::extractNick($user);
        $collator   = $this->connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->nicks);
        if ($key === false) {
            return;
        }

        $this->chans[$event->getChan()][$key][] =
            \Erebot\Utils::getVStatic($event, 'MODE_LETTER');
    }

    /**
     * Handles someone losing his status on an IRC channel,
     * for example, when someone is DEOPped.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::ChanModeTaken $event
     *      Event indicating someone's status changed
     *      on an IRC channel the bot is currently on,
     *      removing privileges from that person.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleChanModeRemoval(
        \Erebot\Interfaces\EventHandler             $handler,
        \Erebot\Interfaces\Event\Base\ChanModeTaken $event
    ) {
        $user       = $event->getTarget();
        $nick       = self::extractNick($user);
        $collator   = $this->connection->getCollator();
        $normNick   = $collator->normalizeNick($nick);
        $key        = array_search($normNick, $this->nicks);
        if ($key === false) {
            return;
        }

        $modeIndex = array_search(
            \Erebot\Utils::getVStatic($event, 'MODE_LETTER'),
            $this->chans[$event->getChan()][$key]
        );
        if ($modeIndex === false) {
            return;
        }

        unset($this->chans[$event->getChan()][$key][$modeIndex]);
    }

    /**
     * Returns a tracking token for some user.
     *
     * \param string $nick
     *      The nickname of some user we want to start tracking.
     *
     * \param string $cls
     *      (optional) Class to use to create the token.
     *      Defaults to Erebot::Module::IrcTracker::Token.
     *
     * \retval mixed
     *      A token that can later be used to return information
     *      on that user (such as his/her nickname, IRC identity,
     *      hostname and whether that person is still online or
     *      not).
     *
     * \throw Erebot::NotFoundException
     *      There is currently no user connected on an IRC channel
     *      the bot is on matching the given nickname.
     *
     * \throw Erebot::InvalidValueException
     *      The given nick is invalid.
     */
    public function startTracking($nick, $cls = '\\Erebot\\Module\\IrcTracker\\Token')
    {
        $identityCls = $this->getFactory('!Identity');
        $fmt = $this->getFormatter(null);
        if ($nick instanceof $identityCls) {
            $identity = $nick;
        } else {
            if (!is_string($nick)) {
                throw new \Erebot\InvalidValueException(
                    $fmt->_('Not a valid nick')
                );
            }
            $identity = new $identityCls($nick);
        }

        $collator   = $this->connection->getCollator();
        $nick       = $collator->normalizeNick($identity->getNick());
        $key        = array_search($nick, $this->nicks);

        if ($key === false) {
            throw new \Erebot\NotFoundException($fmt->_('No such user'));
        }
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
     *      one of Erebot::Module::IrcTracker::INFO_HOST or
     *      Erebot::Module::IrcTracker::INFO_MASK as the
     *      value for $info. In this case, you may pass an
     *      array containing a boolean ($canonical) indicating
     *      the type of hostname canonicalization to apply.
     *      See also Erebot::Interfaces::Identity::getHost()
     *      for more information on the $canonical parameter.
     *
     * \retval mixed
     *      Requested information about that user.
     *      This may be \b null if the requested information
     *      has not been obtained yet.
     *
     * \throw Erebot::InvalidValueException
     *      The value for $info is invalid.
     *
     * \throw Erebot::NotFoundException
     *      The given $token does not match any known user.
     *
     * \warning
     *      This method is not meant to be called directly.
     *      Instead, you should call the equivalent methods
     *      (getNick(), getHost(), etc.) from the token
     *      returned by Erebot::Module::IrcTracker::startTracking().
     */
    public function getInfo($token, $info, $args = array())
    {
        if ($token instanceof \Erebot\Module\IrcTracker\Token) {
            $methods = array(
                self::INFO_ISON     => 'isOn',
                self::INFO_MASK     => 'getMask',
                self::INFO_NICK     => 'getNick',
                self::INFO_IDENT    => 'getIdent',
                self::INFO_HOST     => 'getHost',
            );
            if (!isset($methods[$info])) {
                throw new \Erebot\InvalidValueException('No such information');
            }
            array_unshift($args, $token, $methods[$info]);
            return call_user_func($args);
        }

        $fmt = $this->getFormatter(null);
        if (is_string($token)) {
            $collator   = $this->connection->getCollator();
            $token      = $collator->normalizeNick(
                self::extractNick($token)
            );
            $token = array_search($token, $this->nicks);
            if ($token === false) {
                throw new \Erebot\NotFoundException(
                    $fmt->_('No such user')
                );
            }
        }

        if (!isset($this->ial[$token])) {
            throw new \Erebot\NotFoundException($fmt->_('No such token'));
        }

        $info = strtolower($info);
        if ($info == 'mask') {
            if ($this->ial[$token]['ident'] === null) {
                return $this->ial[$token]['nick'].'!*@*';
            }
            return  $this->ial[$token]['nick'].'!'.
                    $this->ial[$token]['ident'].'@'.
                    $this->ial[$token]['host'];
        }

        if (!array_key_exists($info, $this->ial[$token])) {
            throw new \Erebot\InvalidValueException(
                $fmt->_('No such information')
            );
        }
        return $this->ial[$token][$info];
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
     *      (a string) or \b null. Defaults to \b null.
     *      When this parameter is \b null, this method
     *      tests whether the bot is on the given
     *      IRC channel or not.
     *
     * \retval bool
     *      \b true is the given user is on that
     *      IRC channel, \b false otherwise.
     */
    public function isOn($chan, $nick = null)
    {
        if ($nick === null) {
            return isset($this->chans[$chan]);
        }

        $nick       = self::extractNick($nick);
        $collator   = $this->connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->nicks);
        if ($key === false) {
            return false;
        }
        return isset($this->chans[$chan][$key]);
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
     * \throw Erebot::NotFoundException
     *      The given nickname does not match any known user.
     */
    public function getCommonChans($nick)
    {
        $nick       = self::extractNick($nick);
        $collator   = $this->connection->getCollator();
        $nick       = $collator->normalizeNick($nick);
        $key        = array_search($nick, $this->nicks);
        if ($key === false) {
            throw new \Erebot\NotFoundException('No such user');
        }

        $results = array();
        foreach ($this->chans as $chan => $users) {
            if (isset($users[$key])) {
                $results[] = $chan;
            }
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
     *      May also be set to \b null to search for
     *      all users known to the bot, no matter
     *      what channels they are currently on.
     *      Defaults to \b null.
     *
     * \retval list
     *      A list with the masks of all users
     *      matching the given criteria.
     *
     * \throw Erebot::NotFoundException
     *      The given channel name does not match
     *      the name of any channel the bot is on.
     */
    public function IAL($mask, $chan = null)
    {
        $results = array();

        if (strpos($mask, '!') === false) {
            $mask .= '!*@*';
        } elseif (strpos($mask, '@') === false) {
            $mask .= '@*';
        }

        $translationTable = array(
            '\\*'   => '.*',
            '\\?'   => '.',
        );
        $pattern = "#^".strtr(preg_quote($mask, '#'), $translationTable)."$#";

        if ($chan !== null) {
            if (!isset($this->chans[$chan])) {
                throw new \Erebot\NotFoundException(
                    'The bot is not on that channel!'
                );
            }

            // Search only matching users on that channel.
            foreach (array_keys($this->chans[$chan]) as $key) {
                $entry  = $this->ial[$key];
                $full   = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
                if (preg_match($pattern, $full) == 1) {
                    $results[] = $full;
                }
            }
            return $results;
        }

        foreach ($this->ial as $entry) {
            $full = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
            if (preg_match($pattern, $full) == 1) {
                $results[] = $full;
            }
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
     * \throw Erebot::NotFoundException
     *      The given channel or nickname is not
     *      not known to the bot.
     */
    public function userPrivileges($chan, $nick)
    {
        if (!isset($this->chans[$chan][$nick])) {
            throw new \Erebot\NotFoundException('No such channel or user');
        }
        return $this->chans[$chan][$nick];
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
     * \throw Erebot::NotFoundException
     *      The bot is not present on the given IRC
     *      channel and so the search cannot succeed.
     */
    public function byChannelModes($chan, $modes, $negate = false)
    {
        if (!isset($this->chans[$chan])) {
            throw new \Erebot\NotFoundException('No such channel');
        }
        if (!is_array($modes)) {
            $modes = array($modes);
        }

        $results = array();
        $nbModes = count($modes);
        foreach ($this->chans[$chan] as $key => $chmodes) {
            if ($nbModes) {
                $commonCount = count(array_intersect($modes, $chmodes));
                if (($commonCount == $nbModes && $negate === false) ||
                    ($commonCount == 0 && $negate === true)) {
                    $results[] = $this->nicks[$key];
                }
            } elseif (((bool) count($chmodes)) == $negate) {
                $results[] = $this->nicks[$key];
            }
        }
        return $results;
    }
}
