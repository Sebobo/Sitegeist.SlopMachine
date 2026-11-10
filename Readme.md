# The MCP server for the Neos Content Repository

This package gives an AI agent a controlled access to a Neos content repository: it exposes
the content repository over the Model Context Protocol (MCP), and it keeps the agent inside
the workspaces it is supposed to work in.

The agent works in **its own personal workspace**, hands finished work over to a **shared
review workspace** that humans review and publish, and it never publishes to the live
workspace. Every capability it has is a separate privilege, so an installation decides what
the agent may do - and what it may not.

The package does not decide how the agent authenticates: the installation does. The setup
below uses a bearer token, which is what this repository uses.

## The workspaces

| Workspace | Who works in it | What it is |
| --- | --- | --- |
| `user-<agent user>` | the agent | the personal workspace of the Neos user the agent is assigned to - the changes of the agent live here |
| the shared review workspace | humans | the workspace the agent hands its work over to; humans review it and publish it to live |
| a topic workspace | the agent, then humans | a branch of the shared review workspace, one per topic - so two agents on different topics do not write over each other |

The agent addresses its own workspace with the regular read and write tools. It cannot
address the live workspace or the workspace of another agent.

## Installation

```console
composer require sitegeist/slopmachine
```

The package pulls in `sitegeist/pandora`, the MCP server for Neos Flow, which exposes the
endpoint `/mcp` of the installation.

For the bearer token authentication of the setup below:

```console
composer require flownative/token-authentication
```

Apply the database migrations - the package has two tables: the assignment of an agent to a Neos
user, and the additional instructions the administrators write:

```console
./flow doctrine:migrate
```

## Setup

### 1. The shared review workspace

The workspace the agent hands its work over to. Its **name** goes into the settings - Neos
generates names like `ki-review-r1bxn` for a workspace titled "KI Review", and the content
repository addresses workspaces by name:

```yaml
# Configuration/Settings.yaml
Sitegeist:
  SlopMachine:
    agent:
      sharedWorkspace: 'ki-review-r1bxn'
```

The workspace is created by the command of the package, which reads the name from the
settings - so the command cannot set up a workspace the agent would not use:

```console
./flow agent:createsharedworkspace
```

The workspace is based on `live`, because a human reviews it and publishes it: the agent never
publishes anything itself.

### 2. The Neos user the agent works as

```console
./flow agent:createuser
```

The command creates the user `mcp-agent` if it does not exist yet, assigns the role
`Sitegeist.SlopMachine:McpAgent` to it, and bases its personal workspace on the shared review
workspace - only then does the agent see what the humans are reviewing, and its own diff is
exactly the handover diff.

The account is a machine identity: it gets a random password that is never printed. The agent
authenticates with a token, never with a password, and the role of the agent denies the
backend login on top of that.

### 3. The token

```console
./flow hashtoken:createhashtoken --role-names "Acme.Ai:McpAgent" --label "MCP agent" --expires-at "2027-01-01"
```

The role named here is the role of the *account* the token authenticates - not the role of the
Neos user from step 2. The token is the only credential of the agent; treat it like a
password.

### 4. The assignment

The token identifies the agent, and the assignment says which Neos user it works as:

```console
./flow agent:assigntouser --agent-id "<the token>" --username "mcp-agent"
```

The agent works in the personal workspace of that user, and the policies of Neos treat it as
its own - see step 7.

### 5. The authentication provider

The token authenticates the MCP endpoint. Without a provider bound to the controller, requests
to `/mcp` stay `Neos.Flow:Anonymous` and the privilege of the endpoint is never granted:

```yaml
# Configuration/Settings.yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Acme.Ai:McpTokenAuthenticator':
            provider: Flownative\TokenAuthentication\Security\HashTokenProvider
            token: Flownative\TokenAuthentication\Security\HashToken
            requestPatterns:
              'Sitegeist.Pandora:McpController':
                pattern: ControllerObjectName
                patternOptions:
                  controllerObjectNamePattern: 'Sitegeist\Pandora\Controller\McpController'
```

No entry point is configured on purpose: an unauthenticated request has to fail rather than
being redirected to a login form, which an MCP client cannot follow.

The second request pattern is for the **preview** of a document (step 8). It is the same type
as the one above on purpose: a request pattern is active when at least one pattern of its type
matches, so a second type would have to match as well - and the token would never be active
at all.

The MCP endpoint is protected against DNS rebinding. The host names it may be addressed under
are configured per installation:

```yaml
# Configuration/Settings.yaml
Sitegeist:
  Pandora:
    http:
      allowedHosts:
        - 'example.com'
```

### 6. The policy of the agent

Every capability is deny-by-default: the role of the account names the capabilities it may
use, and a capability that is not granted is silently hidden from the agent. The list of the
capabilities of this package is in the header of the `Policy.yaml` of the package - and
`./flow capability:list --role "Acme.Ai:McpAgent"` shows what a role unlocks.

The package ships two roles for the agent, so an installation does not have to name the
capabilities itself:

| Role | What it grants |
| --- | --- |
| `Sitegeist.SlopMachine:McpAgent` | the read-only baseline: everything that only reads |
| `Sitegeist.SlopMachine:McpAgentWithWriteAccess` | the baseline plus the capabilities that create, change or remove content |

An installation that lets its agent write grants the second one - either directly, or through
a role of its own that inherits from it:

```yaml
# Configuration/Policy.yaml
roles:
  'Acme.Ai:McpAgent':
    label: 'MCP Agent'
    description: 'Lets a token-authenticated agent drive the content repository over /mcp'
    # The capabilities of the agent and the workspace access. The role of the package inherits
    # from Neos.Neos:RestrictedEditor, which grants the access to the internal workspaces - the
    # shared review workspace and the topic workspaces - and nothing else, so a leaked token
    # does not become a backend editor.
    parentRoles: ['Sitegeist.SlopMachine:McpAgentWithWriteAccess']
```

An installation that wants the agent to read and nothing else inherits from
`Sitegeist.SlopMachine:McpAgent` instead. A role of its own is only needed when the agent
shall have more or less than the package grants - for example when the agent may not remove
content, in which case the writing capabilities are granted one by one, as the header of the
`Policy.yaml` of the package lists them.

```yaml
# Configuration/Policy.yaml
roles:
  'Acme.Ai:McpAgent':
    label: 'MCP Agent'
    description: 'Lets a token-authenticated agent drive the content repository over /mcp'
    parentRoles: ['Sitegeist.SlopMachine:McpAgent']
    privileges:
      # No login with a password: the agent is authenticated by a bearer token.
      - privilegeTarget: 'Neos.Neos:BackendLogin'
        permission: DENY

      # The endpoint itself.
      - privilegeTarget: 'Sitegeist.Pandora:MCP'
        permission: GRANT

      # The preview of a document is a frontend request, so it needs the privilege of the
      # frontend - and the account of the agent has no Neos role that would grant it.
      - privilegeTarget: 'Neos.Neos:ContentPreview'
        permission: GRANT

      # Reading
      - privilegeTarget: 'Sitegeist.SlopMachine:Capability.Tool.find-children'
        permission: GRANT
      - privilegeTarget: 'Sitegeist.SlopMachine:Capability.Tool.find-descendants'
        permission: GRANT
      - privilegeTarget: 'Sitegeist.SlopMachine:Capability.Tool.find-subtree'
        permission: GRANT
      - privilegeTarget: 'Sitegeist.SlopMachine:Capability.Tool.dimensionspace'
        permission: GRANT
      # ... one entry per capability, see ./flow capability:list
```

The capabilities of this package:

| Capability | What it does |
| --- | --- |
| `find-children`, `find-descendants`, `find-subtree` | read the content graph of the own workspace |
| `find-content`, `find-documents` | the content of one document, the documents below one |
| `nodetype-full-schema`, `nodetype-basic-schema` | the schema of a node type |
| `dimensionspace` | the dimension space points the installation allows |
| `search-sites`, `list-site-urls` | the sites and the URLs they are reached under |
| `query-patterns` | how to encode the parameters of the read tools |
| `find-asset-collections`, `find-tags`, `find-types`, `find-assets` | read the media |
| `upload-asset` | upload a file into the media collection |
| `CreateNodeAggregateWithNode`, `SetNodeProperties`, `SetNodeReferences`, `BulkWriting`, `MoveNode` | write content |
| `ListUnpublishedChanges` | what the agent has changed in its own workspace |
| `DiscardChanges` | revert the changes of the agent |
| `RemoveNode` | remove content the agent has created |
| `PublishOwnWorkspace` | the hand-over into the shared review workspace |
| `CreateTopicWorkspace`, `RebaseOwnWorkspace` | the branch of the shared review workspace |
| `find-children-in-shared-workspace`, `find-descendants-in-shared-workspace` | read the workspace the agent hands over to |
| `RemoveFromSharedWorkspace` | remove content from the workspace the agent hands over to |
| `PreviewDocument` | the URL of the rendered page of a document |

### 7. The user information of the policies

The policies of Neos compare the owner of a workspace with `current.userInformation.backendUser`
- the Neos user of the authenticated account. The account of the token has no Neos user of its
own, so without this the personal workspace of the agent would look like the personal
workspace of somebody else, and it would be closed: the rule that protects the personal
workspaces of other users is granted to nobody.

```yaml
# Configuration/Settings.yaml
Neos:
  Flow:
    aop:
      globalObjects:
        userInformation: Sitegeist\SlopMachine\Security\AgentAwareUserService
```

The service answers with the user the agent is assigned to when the current account is a token
account, and behaves like the Neos user service for every other account.

**Where this setting goes matters.** It has to be merged *after* the settings of the `Neos.Neos`
package, which define the default - and the settings of a package are merged before the
settings of the application package. So it belongs in the settings of a distribution package
or in the application level `Configuration/Settings.yaml`, not in the settings of this package.

### 8. The client

The client connects to the endpoint of the installation and sends the token:

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "my-mcp": {
      "type": "remote",
      "url": "https://example.com/mcp",
      "enabled": true,
      "oauth": false,
      "headers": {
        "Authorization": "Bearer <the token>"
      }
    }
  }
}
```

### 9. The preview of a document

`PreviewDocument` returns the URL of the preview - the rendered page, not the content of the
document. The preview is a frontend request, and it needs the token as well; without it the
frontend answers with the login page instead of the page:

```console
curl -H "Authorization: Bearer <the token>" "<the url>"
```

The preview of the own workspace shows the changes of the agent, the preview of the workspace
it hands its work over to shows the state a reviewer sees. The preview of a personal workspace
of another user is denied by the policies.

## The commands

| Command | What it does |
| --- | --- |
| `./flow agent:createuser [--username "mcp-agent"]` | the Neos user the agent works as |
| `./flow agent:assigntouser --agent-id "<token>" --username "<user>"` | which Neos user the agent works as |
| `./flow agent:createsharedworkspace [--workspace "<name>"]` | the shared review workspace |
| `./flow capability:list [--role "<role>"]` | the capabilities and what a role unlocks |
| `./flow instructions:show --roles "<roles>"` | the instructions the agents of a role are told |

## The instructions

The agents are told what they may do by the instruction providers of this package and of
Sitegeist.Pandora. They are assigned per role:

```yaml
# Configuration/Settings.yaml
Sitegeist:
  Pandora:
    instructions:
      'Acme.Ai:McpAgent':
        contentRepository: 'Sitegeist\SlopMachine\Domain\ContentRepositoryInstructionsProvider'
        sharedWorkspaces: 'Sitegeist\SlopMachine\Domain\SharedWorkspaceInstructionsProvider'
```

The administrators of the installation add their own instructions in the backend module
"Agent Instructions" - the module is granted to `Neos.Neos:Administrator` by the `Policy.yaml`
of this package.

## What the policies enforce

The capabilities of this package run **under the policies of Neos** - none of them disables the
authorization checks. The policies are therefore the boundary, not the code of the tool:

- the agent reads and writes in its own personal workspace, in the shared review workspace and
  in the topic workspaces - the internal workspaces it is based on;
- the personal workspaces of other users are closed;
- publishing to the live workspace is denied by `Neos.Neos:Backend.PublishToLiveWorkspace`;
- the backend of the installation is closed, the account of the agent has no privilege for it.

The live workspace is public content - anybody may read it, the agent included. The tools of
the agent do not address it.

A capability can only narrow this down further: the tools address the workspace of the agent
by construction, no argument of a tool can select a workspace.

The privileges of the content repository are not declared in most installations, so the
boundary is per **workspace**, not per **node**. Declaring the node privileges of Neos makes
the boundary finer - that is a project of its own.

## Configuration

| Setting | Default | What it does |
| --- | --- | --- |
| `Sitegeist:SlopMachine:agent:sharedWorkspace` | `~` | the name of the workspace the agent hands its work over to; unset disables the shared workspace tools |
| `Sitegeist:SlopMachine:agent:media:collectionTitle` | `MCP Uploads` | the asset collection every upload goes into |
| `Sitegeist:SlopMachine:agent:media:maxFileSize` | `5242880` | the largest accepted upload, in bytes |
| `Sitegeist:SlopMachine:agent:media:allowedMediaTypes` | images and PDF | the media types that may be uploaded |
| `Sitegeist:SlopMachine:agent:media:tagLabel` | `mcp-upload` | the tag every upload carries, so agent uploads are easy to find |

## Development

```console
composer lint          # phpcs and phpstan
vendor/bin/phpstan analyse -c phpstan.neon
```

### The tests

The tests are functional tests: they run against a content repository and let the **policies of
Neos** decide what the agent may do. They need an installation with Neos - a Flow installation
is not enough, because the content repository and the node types come with Neos:

```console
FLOW_CONTEXT=Testing bin/phpunit --colors -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml Packages/Application/Sitegeist.SlopMachine/Tests/Functional/
```

What they hold:

- the agent may read its own personal workspace and the shared review workspace,
- the agent may **not** read the personal workspace of another user,
- the agent may **not** publish to the live workspace,
- the agent may **not** log in with a password,
- the agent may **not** reach its own workspace without the assignment of the agent to a user,
- a capability that is not granted is not visible to the agent,
- no capability switches the authorization checks off,
- the URL of the preview names the workspace it renders and carries no credential.

A new capability is a class with an `#[McpTool]` attribute in `Classes/Domain`. It is
discovered automatically, and it gets a privilege target
`Sitegeist.SlopMachine:Capability.Tool.<the name of the tool>` - which has to be granted in the
`Policy.yaml` of the installation, or the tool stays hidden.
