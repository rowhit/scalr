<?php

use Scalr\Acl\Acl;
use Scalr\Service\Aws\Ec2\DataType as Ec2DataType;
use Scalr\Modules\PlatformFactory;
use Scalr\Modules\Platforms\Ec2\Ec2PlatformModule;

class Scalr_UI_Controller_Tools_Aws_Ec2_Ebs_Snapshots extends Scalr_UI_Controller
{
    const CALL_PARAM_NAME = 'snapshotId';

    public function hasAccess()
    {
        return parent::hasAccess() && $this->request->isAllowed(Acl::RESOURCE_AWS_SNAPSHOTS);
    }

    public function defaultAction()
    {
        $this->viewAction();
    }

    public function viewAction()
    {
        $this->response->page('ui/tools/aws/ec2/ebs/snapshots/view.js', array(
            'locations'	=> self::loadController('Platforms')->getCloudLocations(SERVER_PLATFORMS::EC2, false)
        ));
    }

    public function xGetMigrateDetailsAction()
    {
        if (!$this->request->getEnvironment()->isPlatformEnabled(SERVER_PLATFORMS::EC2))
            throw new Exception('You can migrate image between regions only on EC2 cloud');

        $platform = PlatformFactory::NewPlatform(SERVER_PLATFORMS::EC2);
        $locationsList = $platform->getLocations($this->environment);

        foreach ($locationsList as $location => $name) {
            if ($location != $this->getParam('cloudLocation'))
                $availableDestinations[] = array('cloudLocation' => $location, 'name' => $name);
        }

        $this->response->data(array(
            'sourceRegion' => $this->getParam('cloudLocation'),
            'availableDestinations' => $availableDestinations,
            'snapshotId' => $this->getParam('snapshotId')
        ));
    }

    public function xMigrateAction()
    {
        $aws = $this->request->getEnvironment()->aws($this->getParam('sourceRegion'));
        $newSnapshotId = $aws->ec2->snapshot->copy(
            $this->getParam('sourceRegion'),
            $this->getParam('snapshotId'),
            sprintf(_("Copy of %s from %s"), $this->getParam('snapshotId'), $this->getParam('sourceRegion')),
            $this->getParam('destinationRegion')
        );

        $this->response->data(array('data' => array('snapshotId' => $newSnapshotId, 'cloudLocation' => $this->getParam('destinationRegion'))));
    }

    public function xCreateAction()
    {
        $this->request->defineParams(array(
            'volumeId',
            'cloudLocation',
            'description'
        ));

        $aws = $this->getEnvironment()->aws($this->getParam('cloudLocation'));
        $snapshot = $aws->ec2->snapshot->create($this->getParam('volumeId'), $this->getParam('description'));

        if (isset($snapshot->snapshotId)) {
            /* @var $volume \Scalr\Service\Aws\Ec2\DataType\VolumeData */
            $volume = $aws->ec2->volume->describe($snapshot->volumeId)->get(0);
            if (count($volume->attachmentSet) && !empty($volume->attachmentSet[0]->instanceId)) {
                $instanceId = $volume->attachmentSet[0]->instanceId;
                try {
                    $dBServer = DBServer::LoadByPropertyValue(EC2_SERVER_PROPERTIES::INSTANCE_ID, $instanceId);
                    $dBFarm = $dBServer->GetFarmObject();
                } catch (Exception $e) {
                }
                if (isset($dBServer) && isset($dBFarm)) {
                    $comment = sprintf(_("Created on farm '%s', server '%s' (Instance ID: %s)"),
                        $dBFarm->Name, $dBServer->serverId, $instanceId
                    );
                }
            } else {
                $comment = '';
            }

            $this->db->Execute("
                INSERT INTO ebs_snaps_info
                SET snapid = ?,
                    comment = ?,
                    dtcreated = NOW(),
                    region = ?
            ", array(
                $snapshot->snapshotId, $comment, $this->getParam('cloudLocation')
            ));

            $this->response->data(array('data' => array('snapshotId' => $snapshot->snapshotId)));
        } else {
            throw new Exception("Unable to create snapshot. Please try again later.");
        }
    }

    public function xRemoveAction()
    {
        $this->request->defineParams(array(
            'snapshotId' => array('type' => 'json'),
            'cloudLocation'
        ));
        $aws = $this->getEnvironment()->aws($this->getParam('cloudLocation'));

        $cnt = 0;
        $errcnt = 0;
        foreach ($this->getParam('snapshotId') as $snapshotId) {
            try {
                $aws->ec2->snapshot->delete($snapshotId);
                $cnt++;
            } catch (Exception $e) {
                $errcnt++;
                $errmsg = $e->getMessage();
            }
        }

        $msg = 'Snapshot' . ($cnt > 1 ? 's have' : ' has') . ' been successfully removed.';

        if ($errcnt != 0)
            $msg .= " {$errcnt} snapshots was not removed due to error: {$errmsg}";

        $this->response->success($msg);
    }

    public function xListSnapshotsAction()
    {
        $this->request->defineParams(array(
            'sort' => array('type' => 'json', 'default' => array('property' => 'snapshotId', 'direction' => 'ASC')),
            'showPublicSnapshots',
            'cloudLocation', 'volumeId', 'snapshotId'
        ));

        $aws = $this->getEnvironment()->aws($this->getParam('cloudLocation'));

        $filter = array();
        if ($this->getParam('snapshotId')) {
            $filter[] = array(
                'name'  => Ec2DataType\SnapshotFilterNameType::snapshotId(),
                'value' => $this->getParam('snapshotId'),
            );
        }

        if ($this->getParam('volumeId')) {
            $filter[] = array(
                'name'  => Ec2DataType\SnapshotFilterNameType::volumeId(),
                'value' => $this->getParam('volumeId'),
            );
        }

        // Rows
        $snapList = $aws->ec2->snapshot->describe(null, null, (empty($filter) ? null : $filter));

        $snaps = array();
        /* @var $snapshot Ec2DataType\SnapshotData */
        foreach ($snapList as $snapshot) {
            $item = array(
                'snapshotId' => $snapshot->snapshotId,
                'volumeId'   => $snapshot->volumeId,
                'volumeSize' => $snapshot->volumeSize,
                'status'     => $snapshot->status,
                'startTime'  => $snapshot->startTime->format('c'),
                'progress'   => $snapshot->progress,
                'volumeSize' => $snapshot->volumeSize,
            );
            if ($snapshot->ownerId != $this->getEnvironment()->getPlatformConfigValue(Ec2PlatformModule::ACCOUNT_ID)) {
                $item['comment'] = $snapshot->description;
                $item['owner'] = $snapshot->ownerId;
                if (!$this->getParam('showPublicSnapshots')) continue;
            } else {
                if ($snapshot->description) {
                    $item['comment'] = $snapshot->description;
                }
                $item['owner'] = 'Me';
            }
            $item['progress'] = (int) preg_replace("/[^0-9]+/", "", $item['progress']);
            unset($item['description']);
            $snaps[] = $item;
        }

        $response = $this->buildResponseFromData($snaps, array('snapshotId', 'volumeId', 'comment', 'owner'));
        foreach ($response['data'] as &$row) {
            $row['startTime'] = Scalr_Util_DateTime::convertTz($row['startTime']);
            if (empty($row['comment'])) {
                $row['comment'] = $this->db->GetOne("SELECT comment FROM ebs_snaps_info WHERE snapid=? LIMIT 1", array(
                    $row['snapshotId']
                ));
            }
        }

        $this->response->data($response);
    }
}
