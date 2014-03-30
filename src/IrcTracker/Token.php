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

namespace Erebot\Module\IrcTracker;

/**
 * An object meant to hold a token, which can subsequently
 * be used to retrieve information on the associated user.
 */
class Token implements \Erebot\Interfaces\Identity
{
    /// Reference to the tracker this token belongs to.
    protected $tracker;

    /// The actuel token, which uniquely identifies users on an IRC network.
    protected $token;

    /**
     * Construct a new token holder.
     *
     * \param Erebot::Module::IrcTracker $tracker
     *      An instance of the tracking module.
     *
     * \param opaque $token
     *      The token to store in this object.
     */
    public function __construct(\Erebot\Module\IrcTracker $tracker, $token)
    {
        $this->tracker  = $tracker;
        $this->token    = $token;
    }

    /**
     * Returns the nickname of the user
     * represented by this identity.
     *
     * \retval mixed
     *      This user's nickname or NULL if unavailable.
     */
    public function getNick()
    {
        return $this->tracker->getInfo(
            $this->token,
            \Erebot\Module\IrcTracker::INFO_NICK
        );
    }

    /**
     * Returns the identity string of the user
     * represented by this identity.
     *
     * \retval mixed
     *      This user's identity string or NULL if unavailable.
     *
     * \note
     *      The name of this method is somewhat misleading,
     *      as it returns the "identity" as defined by the
     *      user in his/her client.
     *      This is not the same as the "identity" represented
     *      here (which contains additional information).
     *      To try to disambiguate, the term "identity string"
     *      has been used when referring to the user-defined
     *      identity.
     */
    public function getIdent()
    {
        return $this->tracker->getInfo(
            $this->token,
            \Erebot\Module\IrcTracker::INFO_IDENT
        );
    }

    /**
     * Returns the host of the user
     * represented by this identity.
     *
     * \param opaque $canonical
     *      Either Erebot::Interfaces::Identity::CANON_IPV4 or
     *      Erebot::Interfaces::Identity::CANON_IPV6, indicating
     *      the type of IP canonicalization to use.
     *
     * \retval string
     *      This user's hostname or NULL if unavailable.
     */
    public function getHost($canonical)
    {
        return $this->tracker->getInfo(
            $this->token,
            \Erebot\Module\IrcTracker::INFO_HOST,
            array($canonical)
        );
    }

    /**
     * Returns a mask which can later be used
     * to match against this user.
     *
     * \param opaque $canonical
     *      Either Erebot::Interfaces::Identity::CANON_IPV4 or
     *      Erebot::Interfaces::Identity::CANON_IPV6, indicating
     *      the type of IP canonicalization to use.
     *
     * \retval string
     *      A mask matching against this user.
     *
     * \note
     *      Fields for which no value is available should be
     *      replaced with '*'. This can result in very generic
     *      masks (eg. "foo!*@*") if not enough information
     *      is known.
     */
    public function getMask($canonical)
    {
        return $this->tracker->getInfo(
            $this->token,
            \Erebot\Module\IrcTracker::INFO_MASK,
            array($canonical)
        );
    }

    /**
     * Indicates whether the person associated with this token
     * is still connected.
     *
     * \retval bool
     *      TRUE if the user associated with this token is
     *      still online, FALSE otherwise.
     */
    public function isOn()
    {
        return $this->tracker->getInfo(
            $this->token,
            \Erebot\Module\IrcTracker::INFO_ISON
        );
    }

    /**
     * This method works like Erebot::Interface::Identity::getNick(),
     * except that if no information is available on the user's
     * nickname, it returns "???".
     *
     * \retval string
     *      This user's nickname or a distinctive value
     *      if it is not available.
     */
    public function __toString()
    {
        try {
            return $this->getNick();
        } catch (\Erebot\NotFoundException $e) {
            return "???";
        }
    }
}
