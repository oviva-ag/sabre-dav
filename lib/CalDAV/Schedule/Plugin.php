<?php

namespace Sabre\CalDAV\Schedule;

use
    Sabre\DAV\Server,
    Sabre\DAV\ServerPlugin,
    Sabre\DAV\Property\Href,
    Sabre\DAV\Property\HrefList,
    Sabre\DAV\PropFind,
    Sabre\DAV\INode,
    Sabre\DAV\IFile,
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface,
    Sabre\VObject,
    Sabre\VObject\Reader,
    Sabre\VObject\Component\VCalendar,
    Sabre\VObject\ITip,
    Sabre\DAVACL,
    Sabre\CalDAV\ICalendar,
    Sabre\CalDAV\ICalendarObject,
    Sabre\DAV\Exception\NotFound,
    Sabre\DAV\Exception\Forbidden,
    Sabre\DAV\Exception\BadRequest,
    Sabre\DAV\Exception\NotImplemented;

/**
 * CalDAV scheduling plugin.
 * =========================
 *
 * This plugin provides the functionality added by the "Scheduling Extensions
 * to CalDAV" standard, as defined in RFC6638.
 *
 * calendar-auto-schedule largely works by intercepting a users request to
 * update their local calendar. If a user creates a new event with attendees,
 * this plugin is supposed to grab the information from that event, and notify
 * the attendees of this.
 *
 * There's 3 possible transports for this:
 * * local delivery
 * * delivery through email (iMip)
 * * server-to-server delivery (iSchedule)
 *
 * iMip is simply, because we just need to add the iTip message as an email
 * attachment. Local delivery is harder, because we both need to add this same
 * message to a local DAV inbox, as well as live-update the relevant events.
 *
 * iSchedule is something for later.
 *
 * @copyright Copyright (C) 2007-2014 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Plugin extends ServerPlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * Reference to main Server object.
     *
     * @var Server
     */
    protected $server;

    /**
     * Returns a list of features for the DAV: HTTP header.
     *
     * @return array
     */
    public function getFeatures() {

        return ['calendar-auto-schedule'];

    }

    /**
     * Returns the name of the plugin.
     *
     * Using this name other plugins will be able to access other plugins
     * using Server::getPlugin
     *
     * @return string
     */
    public function getPluginName() {

        return 'caldav-schedule';

    }

    /**
     * Initializes the plugin
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server) {

        $this->server = $server;
        $server->on('method:POST',         [$this, 'httpPost']);
        $server->on('propFind',            [$this, 'propFind']);
        $server->on('beforeCreateFile',    [$this, 'beforeCreateFile']);
        $server->on('beforeWriteContent',  [$this, 'beforeWriteContent']);
        $server->on('beforeUnbind',        [$this, 'beforeUnbind']);
        $server->on('schedule',            [$this, 'scheduleLocalDelivery']);

        /**
         * This information ensures that the {DAV:}resourcetype property has
         * the correct values.
         */
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IOutbox'] = '{urn:ietf:params:xml:ns:caldav}schedule-outbox';
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IInbox'] = '{urn:ietf:params:xml:ns:caldav}schedule-inbox';

        /**
         * Properties we protect are made read-only by the server.
         */
        array_push($server->protectedProperties,
            '{' . self::NS_CALDAV . '}schedule-inbox-URL',
            '{' . self::NS_CALDAV . '}schedule-outbox-URL',
            '{' . self::NS_CALDAV . '}calendar-user-address-set',
            '{' . self::NS_CALDAV . '}calendar-user-type'
        );

    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $uri
     * @return array
     */
    public function getHTTPMethods($uri) {

        try {
            $node = $this->server->tree->getNodeForPath($uri);
        } catch (NotFound $e) {
            return [];
        }

        if ($node instanceof IOutbox) {
            return ['POST'];
        }

        return [];

    }

    /**
     * This method handles POST request for the outbox.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpPost(RequestInterface $request, ResponseInterface $response) {

        // Checking if this is a text/calendar content type
        $contentType = $request->getHeader('Content-Type');
        if (strpos($contentType, 'text/calendar')!==0) {
            return;
        }

        $path = $request->getPath();

        // Checking if we're talking to an outbox
        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (NotFound $e) {
            return;
        }
        if (!$node instanceof IOutbox)
            return;

        $this->server->transactionType = 'post-caldav-outbox';
        $this->outboxRequest($node, $request, $response);

        // Returning false breaks the event chain and tells the server we've
        // handled the request.
        return false;

    }

    /**
     * This method handler is invoked during fetching of properties.
     *
     * We use this event to add calendar-auto-schedule-specific properties.
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    public function propFind(PropFind $propFind, INode $node) {

        if (!$node instanceof DAVACL\IPrincipal) return;

        $caldavPlugin = $this->server->getPlugin('caldav');
        $principalUrl = $node->getPrincipalUrl();

        // schedule-outbox-URL property
        $propFind->handle('{' . self::NS_CALDAV . '}schedule-outbox-URL' , function() use ($principalUrl, $caldavPlugin) {

            $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
            $outboxPath = $calendarHomePath . '/outbox';

            return new Href($outboxPath);

        });
        // schedule-inbox-URL property
        $propFind->handle('{' . self::NS_CALDAV . '}schedule-inbox-URL' , function() use ($principalUrl, $caldavPlugin) {

            $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
            $inboxPath = $calendarHomePath . '/inbox';

            return new Href($inboxPath);

        });

        $propFind->handle('{' . self::NS_CALDAV . '}schedule-default-calendar-URL', function() use ($principalUrl, $caldavPlugin) {

            // We don't support customizing this property yet, so in the
            // meantime we just grab the first calendar in the home-set.
            $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);

            $nodes = $this->server->tree->getNodeForPath($calendarHomePath)->getChildren();

            foreach($nodes as $node) {

                if ($node instanceof ICalendar) {

                    return new Href($calendarHomePath . '/' . $node->getName());

                }

            }

        });

        // The server currently reports every principal to be of type
        // 'INDIVIDUAL'
        $propFind->handle('{' . self::NS_CALDAV . '}calendar-user-type', function() {

            return 'INDIVIDUAL';

        });

    }

    /**
     * This method is called before a new node is created.
     *
     * @param string $path path to new object.
     * @param string|resource $data Contents of new object.
     * @param \Sabre\DAV\INode $parentNode Parent object
     * @param bool $modified Wether or not the item's data was modified/
     * @return bool|null
     */
    public function beforeCreateFile($path, &$data, $parentNode, &$modified) {

        if (!$parentNode instanceof ICalendar) {
            return;
        }

        // It's a calendar, so the contents are most likely an iCalendar
        // object. It's time to start processing this.
        //
        // This step also ensures that $data is re-propagated with a string
        // version of the object.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $vObj = Reader::read($data);

        $addresses = $this->getAddressesForPrincipal(
            $parentNode->getOwner()
        );

        $this->processICalendarChange(null, $vObj, $addresses);

        // After all this exciting action we set $data to the updated event
        // that contains all the new status information (if any).
        $newData = $vObj->serialize();
        if ($newData !== $data) {
            $data = $newData;

            // Setting $modified tells sabredav that the object has changed,
            // and that no ETag must be sent back.
            $modified = true;
        }

    }

    /**
     * This method is triggered before a file gets updated with new content.
     *
     * We use this event to process any changes to scheduling objects.
     *
     * @param string $path
     * @param IFile $node
     * @param resource|string $data
     * @param bool $modified
     * @return void
     */
    public function beforeWriteContent($path, IFile $node, &$data, &$modified) {

        if (!$node instanceof ICalendarObject) {
            return;
        }

        // It's a calendar, so the contents are most likely an iCalendar
        // object. It's time to start processing this.
        //
        // This step also ensures that $data is re-propagated with a string
        // version of the object.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $vObj = Reader::read($data);

        $addresses = $this->getAddressesForPrincipal(
            $node->getOwner()
        );

        $oldObj = Reader::read($node->get());

        $this->processICalendarChange($oldObj, $vObj, $addresses);

        // After all this exciting action we set $data to the updated event
        // that contains all the new status information (if any).
        $newData = $vObj->serialize();
        if ($newData !== $data) {
            $data = $newData;

            // Setting $modified tells sabredav that the object has changed,
            // and that no ETag must be sent back.
            $modified = true;
        }
    }

    /**
     * This method is responsible for delivering the ITip message.
     *
     * @param ITip\Message $itipMessage
     * @return void
     */
    public function deliver(ITip\Message $iTipMessage) {

        /*
        $iTipMessage->scheduleStatus =
            $this->iMIPMessage($iTipMessage->sender, [$iTipMessage->recipient], $iTipMessage->message, '');
        */

        $this->server->emit('schedule', [$iTipMessage]);
        if (!$iTipMessage->scheduleStatus) {
            $iTipMessage->scheduleStatus='5.2;There was no system capable of delivering the scheduling message';
        }

    }

    /**
     * This method is triggered before a file gets deleted.
     *
     * We use this event to make sure that when this happens, attendees get
     * cancellations, and organizers get 'DECLINED' statuses.
     *
     * @param string $path
     * @return void
     */
    public function beforeUnbind($path) {

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject) {
            return;
        }

        $addresses = $this->getAddressesForPrincipal(
            $node->getOwner()
        );

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach($messages as $message) {
            $this->deliver($message);
        }

    }

    /**
     * Event handler for the 'schedule' event.
     *
     * This handler attempts to look at local accounts to deliver the
     * scheduling object.
     *
     * @param ITipImessage $iTipMessage
     * @return void
     */
    public function scheduleLocalDelivery($iTipMessage) {

        $aclPlugin = $this->server->getPlugin('acl');

        // Local delivery is not available if the ACL plugin is not loaded.
        if (!$aclPlugin) {
            return;
        }

        $caldavNS = '{' . Plugin::NS_CALDAV . '}';

        $result = $aclPlugin->principalSearch(
            ['{http://sabredav.org/ns}email-address' => substr($iTipMessage->recipient, 7)],
            [
                '{DAV:}principal-URL',
                 $caldavNS . 'calendar-home-set',
                 $caldavNS . 'schedule-inbox-URL',
                 $caldavNS . 'schedule-default-calendar-URL',
                '{http://sabredav.org/ns}email-address',
            ]
        );

        if (!count($result)) {
            $iTipMessage->scheduleStatus = '3.7; Could not find principal with email: ' . $iTipMessage->recipient;
            return;
        }

        if (!isset($result[0][200][$caldavNS . 'schedule-inbox-URL'])) {
            $iTipMessage->scheduleStatus = '5.2; Could not find local inbox';
            return;
        }
        if (!isset($result[0][200][$caldavNS . 'calendar-home-set'])) {
            $iTipMessage->scheduleStatus = '5.2; Could not locate a calendar-home-set';
            return;
        }
        if (!isset($result[0][200][$caldavNS . 'schedule-default-calendar-URL'])) {
            $iTipMessage->scheduleStatus = '5.2; Could not find a schedule-default-calendar-URL property';
            return;
        }

        $calendarPath = $result[0][200][$caldavNS . 'schedule-default-calendar-URL']->getHref();
        $homePath = $result[0][200][$caldavNS . 'calendar-home-set']->getHref();
        $inboxPath = $result[0][200][$caldavNS . 'schedule-inbox-URL']->getHref();

        // Note that we are bypassing ACL on purpose by calling this directly.
        // We may need to look a bit deeper into this later. Supporting ACL
        // here would be nice.
        if (!$aclPlugin->checkPrivileges($inboxPath, '{' . self::NS_CALDAV . '}schedule-deliver-invite', DAVACL\Plugin::R_PARENT, false)) {
            $iTipMessage->scheduleStatus = '3.8; organizer did not have the schedule-deliver-invite privilege on the attendees inbox';
            return;
        }

        // Next, we're going to find out if the item already exits in one of
        // the users' calendars.
        $uid = $iTipMessage->uid;

        $newFileName = 'sabredav-' . \Sabre\DAV\UUIDUtil::getUUID() . '.ics';

        $home = $this->server->tree->getNodeForPath($homePath);
        $inbox = $this->server->tree->getNodeForPath($inboxPath);

        $currentObject = null;
        $objectNode = null;
        $isNewNode = false;

        $result = $home->getCalendarObjectByUID($uid);
        if ($result) {
            // There was an existing object, we need to update probably.
            $objectPath = $homePath . '/' . $result;
            $objectNode = $this->server->tree->getNodeForPath($objectPath);
            $oldICalendarData = $objectNode->get();
            if (is_resource($oldICalendarData)) {
                $oldICalendarData = stream_get_contents($oldICalendarData);
            }
            $currentObject = Reader::read($oldICalendarData);
        } else {
            $isNewNode = true;
        }

        $broker = new ITip\Broker();
        $newObject = $broker->processMessage($iTipMessage, $currentObject);

        $inbox->createFile($newFileName, $iTipMessage->message->serialize());

        if (!$newObject) {
            // We received an iTip message referring to a UID that we don't
            // have in any calendars yet, and processMessage did not give us a
            // calendarobject back.
            //
            // The implication is that processMessage did not understand the
            // iTip message.
            $iTipMessage->scheduleStatus = '5.0;iTip message was not processed by the server, likely because we didn\'t understand it.';
            return;
        }

        if ($isNewNode) {
            $calendar = $this->server->tree->getNodeForPath($calendarPath);
            $calendar->createFile($newFileName, $newObject->serialize());
        } else {
            // If the message was a reply, we may have to inform other
            // attendees of this attendees status. Therefore we're shooting off
            // another itipMessage.
            if ($message->itipMessage->method === 'REPLY') {
                $this->processICalendarChange(
                    $oldICalendarData,
                    $newObject,
                    [$message->recipient],
                    [$message->sender]
                );
            }
            $objectNode->put($newObject->serialize());
        }
        $iTipMessage->scheduleStatus = '1.2;Message delivered locally';

    }

    /**
     * This method looks at an old iCalendar object, a new iCalendar object and
     * starts sending scheduling messages based on the changes.
     *
     * A list of addresses needs to be specified, so the system knows who made
     * the update, because the behavior may be different based on if it's an
     * attendee or an organizer.
     *
     * This method may update $newObject to add any status changes.
     *
     * @param VCalendar $oldObject
     * @param VCalendar $newObject
     * @param array $addresses
     * @param array $ignore Any addresses to not send messages to.
     * @return void
     */
    protected function processICalendarChange(VCalendar $oldObject = null, VCalendar $newObject, array $addresses, array $ignore = []) {

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent($newObject, $addresses, $oldObject);

        foreach($messages as $message) {

            if (in_array($message->recipient, $ignore)) {
                continue;
            }

            $this->deliver($message);

            if (isset($newObject->VEVENT->ORGANIZER) && ($newObject->VEVENT->ORGANIZER->getValue() === $message->recipient)) {
                $newObject->VEVENT->ORGANIZER['SCHEDULE-STATUS'] = $message->scheduleStatus;

            } else {

                foreach($newObject->VEVENT->ATTENDEE as $attendee) {

                    if ($attendee->getValue() === $message->recipient) {
                        $attendee['SCHEDULE-STATUS'] = $message->scheduleStatus;
                        break;
                    }

                }

            }

        }

    }

    /**
     * Returns a list of addresses that are associated with a principal.
     *
     * @param string $principal
     * @return array
     */
    protected function getAddressesForPrincipal($principal) {

        $CUAS = '{' . self::NS_CALDAV . '}calendar-user-address-set';

        $properties = $this->server->getProperties(
            $principal,
            [$CUAS]
        );

        // If we can't find this information, we'll stop processing
        if (!isset($properties[$CUAS])) {
            return;
        }

        $addresses = $properties[$CUAS]->getHrefs();
        return $addresses;

    }

    /**
     * This method handles POST requests to the schedule-outbox.
     *
     * Currently, two types of requests are support:
     *   * FREEBUSY requests from RFC 6638
     *   * Simple iTIP messages from draft-desruisseaux-caldav-sched-04
     *
     * The latter is from an expired early draft of the CalDAV scheduling
     * extensions, but iCal depends on a feature from that spec, so we
     * implement it.
     *
     * @param IOutbox $outboxNode
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function outboxRequest(IOutbox $outboxNode, RequestInterface $request, ResponseInterface $response) {

        $outboxPath = $request->getPath();

        // Parsing the request body
        try {
            $vObject = VObject\Reader::read($request->getBody());
        } catch (VObject\ParseException $e) {
            throw new BadRequest('The request body must be a valid iCalendar object. Parse error: ' . $e->getMessage());
        }

        // The incoming iCalendar object must have a METHOD property, and a
        // component. The combination of both determines what type of request
        // this is.
        $componentType = null;
        foreach($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                break;
            }
        }
        if (is_null($componentType)) {
            throw new BadRequest('We expected at least one VTODO, VJOURNAL, VFREEBUSY or VEVENT component');
        }

        // Validating the METHOD
        $method = strtoupper((string)$vObject->METHOD);
        if (!$method) {
            throw new BadRequest('A METHOD property must be specified in iTIP messages');
        }

        // So we support two types of requests:
        //
        // REQUEST with a VFREEBUSY component
        // REQUEST, REPLY, ADD, CANCEL on VEVENT components

        $acl = $this->server->getPlugin('acl');

        if ($componentType === 'VFREEBUSY' && $method === 'REQUEST') {

            $acl && $acl->checkPrivileges($outboxPath, '{' . self::NS_CALDAV . '}schedule-query-freebusy');
            $this->handleFreeBusyRequest($outboxNode, $vObject, $request, $response);

        } else {

            throw new NotImplemented('We only support VFREEBUSY (REQUEST) on this endpoint');

        }

    }

    /**
     * This method is responsible for parsing a free-busy query request and
     * returning it's result.
     *
     * @param IOutbox $outbox
     * @param VObject\Component $vObject
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return string
     */
    protected function handleFreeBusyRequest(IOutbox $outbox, VObject\Component $vObject, RequestInterface $request, ResponseInterface $response) {

        $vFreeBusy = $vObject->VFREEBUSY;
        $organizer = $vFreeBusy->organizer;

        $organizer = (string)$organizer;

        // Validating if the organizer matches the owner of the inbox.
        $owner = $outbox->getOwner();

        $caldavNS = '{' . self::NS_CALDAV . '}';

        $uas = $caldavNS . 'calendar-user-address-set';
        $props = $this->server->getProperties($owner, [$uas]);

        if (empty($props[$uas]) || !in_array($organizer, $props[$uas]->getHrefs())) {
            throw new Forbidden('The organizer in the request did not match any of the addresses for the owner of this inbox');
        }

        if (!isset($vFreeBusy->ATTENDEE)) {
            throw new BadRequest('You must at least specify 1 attendee');
        }

        $attendees = [];
        foreach($vFreeBusy->ATTENDEE as $attendee) {
            $attendees[]= (string)$attendee;
        }


        if (!isset($vFreeBusy->DTSTART) || !isset($vFreeBusy->DTEND)) {
            throw new BadRequest('DTSTART and DTEND must both be specified');
        }

        $startRange = $vFreeBusy->DTSTART->getDateTime();
        $endRange = $vFreeBusy->DTEND->getDateTime();

        $results = [];
        foreach($attendees as $attendee) {
            $results[] = $this->getFreeBusyForEmail($attendee, $startRange, $endRange, $vObject);
        }

        $dom = new \DOMDocument('1.0','utf-8');
        $dom->formatOutput = true;
        $scheduleResponse = $dom->createElement('cal:schedule-response');
        foreach($this->server->xmlNamespaces as $namespace=>$prefix) {

            $scheduleResponse->setAttribute('xmlns:' . $prefix,$namespace);

        }
        $dom->appendChild($scheduleResponse);

        foreach($results as $result) {
            $xresponse = $dom->createElement('cal:response');

            $recipient = $dom->createElement('cal:recipient');
            $recipientHref = $dom->createElement('d:href');

            $recipientHref->appendChild($dom->createTextNode($result['href']));
            $recipient->appendChild($recipientHref);
            $xresponse->appendChild($recipient);

            $reqStatus = $dom->createElement('cal:request-status');
            $reqStatus->appendChild($dom->createTextNode($result['request-status']));
            $xresponse->appendChild($reqStatus);

            if (isset($result['calendar-data'])) {

                $calendardata = $dom->createElement('cal:calendar-data');
                $calendardata->appendChild($dom->createTextNode(str_replace("\r\n","\n",$result['calendar-data']->serialize())));
                $xresponse->appendChild($calendardata);

            }
            $scheduleResponse->appendChild($xresponse);
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type','application/xml');
        $response->setBody($dom->saveXML());

    }

    /**
     * Returns free-busy information for a specific address. The returned
     * data is an array containing the following properties:
     *
     * calendar-data : A VFREEBUSY VObject
     * request-status : an iTip status code.
     * href: The principal's email address, as requested
     *
     * The following request status codes may be returned:
     *   * 2.0;description
     *   * 3.7;description
     *
     * @param string $email address
     * @param \DateTime $start
     * @param \DateTime $end
     * @param VObject\Component $request
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTime $start, \DateTime $end, VObject\Component $request) {

        $caldavNS = '{' . Plugin::NS_CALDAV . '}';

        $aclPlugin = $this->server->getPlugin('acl');
        if (substr($email,0,7)==='mailto:') $email = substr($email,7);

        $result = $aclPlugin->principalSearch(
            ['{http://sabredav.org/ns}email-address' => $email],
            [
                '{DAV:}principal-URL', $caldavNS . 'calendar-home-set',
                '{http://sabredav.org/ns}email-address',
            ]
        );

        if (!count($result)) {
            return [
                'request-status' => '3.7;Could not find principal',
                'href' => 'mailto:' . $email,
            ];
        }

        if (!isset($result[0][200][$caldavNS . 'calendar-home-set'])) {
            return [
                'request-status' => '3.7;No calendar-home-set property found',
                'href' => 'mailto:' . $email,
            ];
        }
        $homeSet = $result[0][200][$caldavNS . 'calendar-home-set']->getHref();

        // Grabbing the calendar list
        $objects = [];
        foreach($this->server->tree->getNodeForPath($homeSet)->getChildren() as $node) {
            if (!$node instanceof ICalendar) {
                continue;
            }
            $aclPlugin->checkPrivileges($homeSet . $node->getName() ,$caldavNS . 'read-free-busy');

            // Getting the list of object uris within the time-range
            $urls = $node->calendarQuery([
                'name' => 'VCALENDAR',
                'comp-filters' => [
                    [
                        'name' => 'VEVENT',
                        'comp-filters' => [],
                        'prop-filters' => [],
                        'is-not-defined' => false,
                        'time-range' => [
                            'start' => $start,
                            'end' => $end,
                        ],
                    ],
                ],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => null,
            ]);

            $calObjects = array_map(function($url) use ($node) {
                $obj = $node->getChild($url)->get();
                return $obj;
            }, $urls);

            $objects = array_merge($objects,$calObjects);

        }

        $vcalendar = new VObject\Component\VCalendar();
        $vcalendar->METHOD = 'REPLY';

        $generator = new VObject\FreeBusyGenerator();
        $generator->setObjects($objects);
        $generator->setTimeRange($start, $end);
        $generator->setBaseObject($vcalendar);

        $result = $generator->getResult();

        $vcalendar->VFREEBUSY->ATTENDEE = 'mailto:' . $email;
        $vcalendar->VFREEBUSY->UID = (string)$request->VFREEBUSY->UID;
        $vcalendar->VFREEBUSY->ORGANIZER = clone $request->VFREEBUSY->ORGANIZER;

        return [
            'calendar-data' => $result,
            'request-status' => '2.0;Success',
            'href' => 'mailto:' . $email,
        ];
    }
}
