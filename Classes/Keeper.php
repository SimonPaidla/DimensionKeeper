<?php
namespace Ttree\DimensionKeeper;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class Keeper
{
    const CONFIGURATION_PATH = 'options.TtreeDimensionKeeper:Properties';

    /**
     * @Flow\Inject(name="Neos.Flow:SystemLogger")
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @var array
     */
    protected $tracker = [];

    /**
     * @var bool
     * @Flow\InjectConfiguration(path="enabled")
     */
    protected $enabled = true;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    public function sync(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, string $propertyName, $oldValue, $newValue)
    {
        if ($this->enabled !== true || !$this->managedProperties($node, $propertyName)) {
            return;
        }
        // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.


        $currentDimensions = $node->originDimensionSpacePoint->toLegacyDimensionArray();
        $this->systemLogger->debug(\vsprintf('Synchronize property %s start in %s', [$propertyName, \json_encode($currentDimensions)]));

        \array_map(function (\Neos\ContentRepository\Core\Projection\ContentGraph\Node $nodeVariant) use ($propertyName, $newValue, $currentDimensions) {
            // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.

            if ($nodeVariant->originDimensionSpacePoint->toLegacyDimensionArray() === $currentDimensions) {
                return;
            }
            $this->systemLogger->debug(\vsprintf('Synchronize property %s to node variant %s', [$propertyName, \Neos\ContentRepository\Core\SharedModel\Node\NodeAddress::fromNode($nodeVariant)->toJson()]));
            $this->skip(function () use ($nodeVariant, $propertyName, $newValue) {
                // TODO 9.0 migration: !! Node::setProperty() is not supported by the new CR. Use the "SetNodeProperties" command to change property values.

                $nodeVariant->setProperty($propertyName, $newValue);
            });
        }, $node->getOtherNodeVariants());
    }

    public function skip(\Closure $closure)
    {
        $previousState = $this->enabled;
        try {
            $this->enabled = false;
            $closure();
        } finally {
            $this->enabled = $previousState;
        }
    }

    protected function managedProperties(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, $propertyName)
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $configuration = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->getConfiguration(self::CONFIGURATION_PATH) ?: [];
        return isset($configuration[$propertyName]) && $configuration[$propertyName] === true;
    }
}
