<?php

declare(strict_types=1);

namespace Sitegeist\SlopMachine\Domain;

use Sitegeist\Pandora\Instruction\InstructionsProvider;

final class ContentRepositoryInstructionsProvider implements InstructionsProvider
{
    public function getInstructions(): string
    {
        return 'Welcome to the Neos Content Repository. The content repository is a property graph structure consisting of nodes and relations.
            Nodes are arranged in a hierarchical tree structure.
            Nodes can also have reference relations to other nodes.
            Nodes are of a specific node type which declares the schema of that node.
            This includes what properties nodes of this type have, what other nodes can be referenced and what type child nodes of nodes of this type may have.
            The Neos Content Repository uses a strictly validated CQRS model. All command or query parameters are strictly validated, errors are clearly communicated.
            Any decisions made by the tools are communicated in their response.
            A `success: true` response confirms the command was accepted; the write itself has happened. Re-read the affected properties when exact values matter: `uriPathSegment` is silently re-suffixed by the content repository on collision (so the stored value can be `beratung-1` while the call reported `beratung`), and a write that became a duplicate is only visible after a read. Use read-back before declaring a write done when the value must match what was sent.
            A batch write answers with the outcome of the batch instead of a single flag: `successCount` and `failureCount` count the commands that succeeded and failed, and `results` holds one entry per command in the order the commands were sent, each with its own `success` flag and, for a created node, its `nodeAggregateId` and the ids of its tethered children. A failed command does not undo the commands before it, so correct the failed ones and send them again.
            Every node a read returns carries its own visibility state next to its properties: hidden, hiddenInIndex, hiddenBeforeDateTime and hiddenAfterDateTime. They are not properties of the node type - the node type does not declare them - but SetNodeProperties accepts them all the same, and reading them back is how a hide or an unhide is confirmed.
            All MCP resources and tools provide a strict schema that is both enforced and completely declared. Always use these schemas when calling the MCP API, never use trial and error.
            Always stick to the schema, do not send undeclared parameters.
            The schema does not have to be validated on the client side, never send any ping or test calls. Those are a pure waste of time.
            Every tool that reads or writes content takes a dimension space point, and the point has to be one this installation allows. Call the dimensionspace tool once at the start to get the points that are valid, and use one of them for all following calls; if it reports a single empty object, that empty object {} is the point to use. A point that names a dimension or value this installation does not have is rejected instead of being silently ignored.
            Every read is capped. When a read reports truncated, it also reports a nextCursor: send that cursor back as the cursor parameter to get the next page. The cursor is a best effort resume position, not a snapshot - nodes that are added to or removed from the subtree in between can change what the next page holds.
            A document owns content of its own and it may also parent other documents, and each of those brings its own content with it. Use find-content for the content of one document and find-documents for the documents below it; find-descendants mixes both and is the expensive one.
            Call the dimensionspace tool on every run rather than assuming the point from a previous run: which dimensions exist is a property of the installation and can change.
            Check with the query-patterns tool on how to encode parameters.
            Assets are not part of the content repository. An asset uploaded with the upload-asset tool is live the moment the call returns: it is not part of any workspace, and neither DiscardChanges nor RemoveNode can take it back. Every upload goes into one asset collection and carries a tag, so that a human can find and remove it again.
            To put an uploaded asset on a page, pass the assetId the upload returned as the value of the image property with SetNodeProperties. The value is stored as the asset itself, not as its id.';
    }
}
