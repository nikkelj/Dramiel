<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 Robert Sardinia
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Channel;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;

/**
 * Class fileAuthCheck
 */
class authCheck
{
    /**
     * @var
     */
    var $config;
    /**
     * @var
     */
    var $db;
    /**
     * @var
     */
    var $discord;
    /**
     * @var
     */
    var $channelConfig;
    /**
     * @var int
     */
    var $lastCheck = 0;
    /**
     * @var
     */
    var $logger;

    /**
     * @param $config
     * @param $discord
     * @param $logger
     */
    function init($config, $discord, $logger)
    {
        $this->config = $config;
        $this->discord = $discord;
        $this->logger = $logger;
        $this->nextCheck = 0;
        $lastCheck = getPermCache("authLastChecked");
        if ($lastCheck == NULL) {
            // Schedule it for right now if first run
            setPermCache("authLastChecked", time() - 5);
        }
    }

    /**
     * @return array
     */
    function information()
    {
        return array(
            "name" => "",
            "trigger" => array(),
            "information" => ""
        );
    }
    function tick()
    {
        $lastChecked = getPermCache("authLastChecked");

        if ($lastChecked <= time()) {
            $this->logger->addInfo("Checking authed users for changes....");
            $this->checkAuth();
        }

    }

    /**
     *
     */
    function checkAuth()
    {
        if ($this->config["plugins"]["auth"]["periodicCheck"] == "true") {
            $db = $this->config["database"]["host"];
            $dbUser = $this->config["database"]["user"];
            $dbPass = $this->config["database"]["pass"];
            $dbName = $this->config["database"]["database"];
            $allyID = $this->config["plugins"]["auth"]["allianceID"];
            $corpID = $this->config["plugins"]["auth"]["corpID"];
            $toDiscordChannel = $this->config["plugins"]["auth"]["alertChannel"];
            $conn = new mysqli($db, $dbUser, $dbPass, $dbName);

            $sql3 = "SELECT characterID, discordID FROM authUsers WHERE active='yes'";

            $result3 = $conn->query($sql3);
            $num_rows2 = $result3->num_rows;

            if ($num_rows2 >= 2) {
                while ($rows = $result3->fetch_assoc()) {
                    $charID = $rows['characterID'];
                    $discordID = $rows['discordID'];
                    $guild = $this->discord->guilds->first();
                    $member = $guild->members->get("id", $discordID);
                    $discordName = $member->user->username;
                    $roles = $member->roles;
                    $url = "https://api.eveonline.com/eve/CharacterAffiliation.xml.aspx?ids=$charID";
                    $xml = makeApiRequest($url);
                    if ($xml->result->rowset->row[0]) {
                        foreach ($xml->result->rowset->row as $character) {
                            if ($character->attributes()->allianceID != $allyID && $character->attributes()->corporationID != $corpID) {
                                foreach ($roles as $role) {
                                    $member->removeRole($role);
                                    $member->save();
                                }

                                // Send the info to the channel
                                $msg = $discordName . " roles have been removed via the auth.";
                                $channelID = $toDiscordChannel;
                                $channel = Channel::find($channelID);
                                $channel->sendMessage($msg, false);
                                $this->logger->addInfo($discordName . " roles ({$role}) have been removed via the auth.");

                                $sql4 = "UPDATE authUsers SET active='no' WHERE discordID='$discordID'";
                                $result4 = $conn->query($sql4);

                            }
                        }
                    }
                }
                $this->logger->addInfo("All users successfully authed.");
                $nextCheck = time() + 7200;
                setPermCache("authLastChecked", $nextCheck);
                if ($this->config["plugins"]["auth"]["nameEnforce"] == "true") {
                    while ($rows = $result3->fetch_assoc()) {
                        $discordID = $rows['discordID'];
                        $eveName = $rows['eveName'];
                        $guild = $this->discord->guilds->first();
                        $member = $guild->members->get("id", $discordID);
                        $discordName = $member->user->username;
                        if ($discordName != $eveName) {
                            foreach ($roles as $role) {
                                $member->removeRole($role);
                            }

                            // Send the info to the channel
                            $msg = $discordName . " roles have been removed because their name no longer matches their ingame name.";
                            $channelID = $toDiscordChannel;
                            $channel = Channel::find($channelID);
                            $channel->sendMessage($msg, false);
                            $this->logger->addInfo($discordName . " roles have been removed because their name no longer matches their ingame name.");

                            $sql4 = "UPDATE authUsers SET active='no' WHERE discordID='$discordID'";
                            $result4 = $conn->query($sql4);
                        }
                    }
                    $this->logger->addInfo("All users names have been checked.");
                    $cacheTimer = gmdate("Y-m-d H:i:s", $nextCheck);
                    $this->logger->addInfo("Next auth and name check at {$cacheTimer} EVE");
                    return null;
                }
                $cacheTimer = gmdate("Y-m-d H:i:s", $nextCheck);
                $this->logger->addInfo("Next auth and name check at {$cacheTimer} EVE");
                return null;
            }
            $this->logger->addInfo("No users found in database.");
            $nextCheck = time() + 7200;
            setPermCache("authLastChecked", $nextCheck);
            $cacheTimer = gmdate("Y-m-d H:i:s", $nextCheck);
            $this->logger->addInfo("Next auth and name check at {$cacheTimer} EVE");
            return null;
        }
        return null;
    }

    /**
     * @param $msgData
     */
    function onMessage($msgData)
    {
    }
}
