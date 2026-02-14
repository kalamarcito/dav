<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use Sabre\DAV;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * This plugin implements support for carddav sharing.
 *
 * Note: This feature is experimental, and may change in between different
 * SabreDAV versions.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class SharingPlugin extends DAV\ServerPlugin
{
    /**
     * Reference to SabreDAV server object.
     *
     * @var DAV\Server
     */
    protected $server;

    /**
     * This method should return a list of server-features.
     *
     * This is for example 'versioning' and is added to the DAV: header
     * in an OPTIONS response.
     *
     * @return array
     */
    public function getFeatures()
    {
        return ['carddav-sharing'];
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using Sabre\DAV\Server::getPlugin
     *
     * @return string
     */
    public function getPluginName()
    {
        return 'carddav-sharing';
    }

    /**
     * This initializes the plugin.
     *
     * This function is called by Sabre\DAV\Server, after
     * addPlugin is called.
     *
     * This method should set up the required event subscriptions.
     */
    public function initialize(DAV\Server $server)
    {
        $this->server = $server;

        if (is_null($this->server->getPlugin('sharing'))) {
            throw new \LogicException('The generic "sharing" plugin must be loaded before the carddav sharing plugin. Call $server->addPlugin(new \Sabre\DAV\Sharing\Plugin()); before this one.');
        }

        array_push(
            $this->server->protectedProperties,
            '{DAV:}invite',
            '{'.Plugin::NS_CARDDAV.'}allowed-sharing-modes'
        );

        $this->server->on('propFind', [$this, 'propFindEarly']);
        $this->server->on('propFind', [$this, 'propFindLate'], 150);
        $this->server->on('propPatch', [$this, 'propPatch'], 40);
        $this->server->on('method:POST', [$this, 'httpPost']);
    }

    /**
     * This event is triggered when properties are requested for a certain
     * node.
     *
     * This allows us to inject any properties early.
     *
     * Note: The {DAV:}invite property is already handled by the generic
     * DAV\Sharing\Plugin for all ISharedNode instances, so we don't need
     * to duplicate it here.
     */
    public function propFindEarly(DAV\PropFind $propFind, DAV\INode $node)
    {
        // Currently a no-op for CardDAV. The DAV Sharing Plugin handles
        // {DAV:}invite and {DAV:}share-access for all ISharedNode instances.
        // If CalendarServer-namespace properties are needed for client
        // compatibility, they can be added here in the future.
    }

    /**
     * This method is triggered *after* all properties have been retrieved.
     * This allows us to inject the correct resourcetype for address books that
     * have been shared.
     */
    public function propFindLate(DAV\PropFind $propFind, DAV\INode $node)
    {
        if ($node instanceof ISharedAddressBook) {
            $shareAccess = $node->getShareAccess();
            if ($rt = $propFind->get('{DAV:}resourcetype')) {
                switch ($shareAccess) {
                    case \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER:
                        $rt->add('{'.Plugin::NS_CARDDAV.'}shared-owner');
                        break;
                    case \Sabre\DAV\Sharing\Plugin::ACCESS_READ:
                    case \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE:
                        $rt->add('{'.Plugin::NS_CARDDAV.'}shared');
                        break;
                }
            }
            // Note: allowed-sharing-modes is a CalendarServer extension.
            // For CardDAV, sharing capabilities are indicated by the
            // resourcetype and {DAV:}share-access properties.
        }
    }

    /**
     * This method is triggered when a user attempts to update a node's
     * properties.
     *
     * A previous draft of the sharing spec stated that it was possible to use
     * PROPPATCH to remove 'shared-owner' from the resourcetype, thus unsharing
     * the address book.
     *
     * Even though this is no longer in the current spec, we keep this around
     * because OS X may still make use of this feature.
     *
     * @param string $path
     */
    public function propPatch($path, DAV\PropPatch $propPatch)
    {
        $node = $this->server->tree->getNodeForPath($path);
        if (!$node instanceof ISharedAddressBook) {
            return;
        }

        if (\Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER === $node->getShareAccess() || \Sabre\DAV\Sharing\Plugin::ACCESS_NOTSHARED === $node->getShareAccess()) {
            $propPatch->handle('{DAV:}resourcetype', function ($value) use ($node) {
                if ($value->is('{'.Plugin::NS_CARDDAV.'}shared-owner')) {
                    return false;
                }
                $shares = $node->getInvites();
                foreach ($shares as $share) {
                    $share->access = DAV\Sharing\Plugin::ACCESS_NOACCESS;
                }
                $node->updateInvites($shares);

                return true;
            });
        }
    }

    /**
     * We intercept this to handle POST requests on address books.
     *
     * @return bool|null
     */
    public function httpPost(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        // Only handling xml
        $contentType = $request->getHeader('Content-Type');
        if (null === $contentType) {
            return;
        }
        if (false === strpos($contentType, 'application/xml') && false === strpos($contentType, 'text/xml')) {
            return;
        }

        // Making sure the node exists
        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (DAV\Exception\NotFound $e) {
            return;
        }

        $requestBody = $request->getBodyAsString();

        // If this request handler could not deal with this POST request, it
        // will return 'null' and other plugins get a chance to handle the
        // request.
        //
        // However, we already requested the full body. This is a problem,
        // because a body can only be read once. This is why we preemptively
        // re-populated the request body with the existing data.
        $request->setBody($requestBody);

        $message = $this->server->xml->parse($requestBody, $request->getUrl(), $documentType);

        switch ($documentType) {
            // The DAV:share-resource request behaves identically to CardDAV sharing
            case '{DAV:}share-resource':
                $sharingPlugin = $this->server->getPlugin('sharing');
                $sharingPlugin->shareResource($path, $message->sharees);

                $response->setStatus(200);
                // Adding this because sending a response body may cause issues,
                // and I wanted some type of indicator the response was handled.
                $response->setHeader('X-Sabre-Status', 'everything-went-well');

                // Breaking the event chain
                return false;
        }
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    public function getPluginInfo()
    {
        return [
            'name' => $this->getPluginName(),
            'description' => 'Adds support for carddav-sharing.',
            'link' => 'http://sabre.io/dav/carddav-sharing/',
        ];
    }
}