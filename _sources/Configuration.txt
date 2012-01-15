Configuration
=============

Options
-------

This module offers no configuration options.


Example
-------

In this example, we make this module available to any network/channel
so that other modules can rely on it. This is the recommended way of using
this module.

..  parsed-code:: xml

    <?xml version="1.0" ?>
    <configuration
      xmlns="http://localhost/Erebot/"
      version="0.20"
      language="fr-FR"
      timezone="Europe/Paris">

      <modules>
        <!-- Other modules ignored for clarity. -->

        <module name="Erebot_Module_IrcTracker"/>
      </modules>
    </configuration>

.. vim: ts=4 et
