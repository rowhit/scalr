<?php
namespace Scalr\Service\CloudStack\DataType;

/**
 * EgressruleList
 *
 * @author    Vlad Dobrovolskiy   <v.dobrovolskiy@scalr.com>
 * @since     4.5.2
 *
 */
class EgressruleList extends AbstractListDataType
{

    /**
     * {@inheritdoc}
     * @see Scalr\Service\CloudStack\DataType.AbstractListDataType::getClass()
     */
    public function getClass()
    {
        return __NAMESPACE__ . '\\EgressruleData';
    }

}