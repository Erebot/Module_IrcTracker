Detailed description
====================

This module provides a way to track users through nick changes and miscellaneous
other events based on their initial nickname. It is meant to help other modules
build nick-independent features (such as a game capable of tracking players,
no matter how much they change their nickname).

This is achieved by associating a token with each user. This token can later
be used to retrieve information about the corresponding user (nickname,
identity, hostname, etc.).

This module also keeps a list with the address of every known user
(called IAL in mIRC's terminology, for "Internal Address List").
This IAL can be queried in the same way tokens can.

Moreover, this module keeps information on the different channels users
have joined where the bot is also present. This information can be queried
to determine if a particular user is present on a given channel.
It can also be used to retrieve a list of common channels between some user
and the bot.

Last but not least, this module tracks user status on channels
and can be used to find all operators on a channel,
the modes affecting a given user on a channel, etc.

.. vim: ts=4 et
