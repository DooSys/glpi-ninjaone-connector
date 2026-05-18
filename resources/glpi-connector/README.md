# GLPI connector for NinjaOne scripts

This folder contains the first proof-of-concept assets for running GLPI Agent
portable from NinjaOne without installing the GLPI Agent Windows service.

## Target endpoint layout

The generated NinjaOne script creates and uses this tree on each Windows
endpoint:

```text
C:\Program Files (x86)\NinjaOne\
  plugin\
    glpi-connector\
      agent\
        glpi-inventory.bat
        glpi-injector.bat
        perl\
        etc\
      inventories\
        pending\
        sent\
      logs\
      packages\
        GLPI-Agent-1.17-x64.zip
```

`agent` contains the extracted GLPI Agent portable archive.
`inventories\pending` contains inventories that were generated but not yet
confirmed as uploaded.
`inventories\sent` contains inventories successfully uploaded when retention is
enabled.
`logs` contains one log file per run.
`packages` is the default location for a pre-staged GLPI Agent portable ZIP.

## Test GLPI URL

Default GLPI base URL for the POC:

```text
https://support.tinisys.fr/
```

Default inventory endpoint built by the script:

```text
https://support.tinisys.fr/front/inventory.php
```

If the GLPI Inventory plugin requires a different endpoint, override
`$GlpiInventoryUrl` in the generated script.

## Intended GLPI plugin flow

Later, the GLPI plugin can expose a "Generate NinjaOne PowerShell" action. The
form should collect:

- GLPI base URL or final inventory URL.
- Inventory tag.
- GLPI Agent ZIP source URL or pre-staged package mode.
- TLS mode: normal validation, CA file, certificate fingerprint, or temporary
  `--no-ssl-check` for lab testing.
- Proxy URL if required.
- Whether successful JSON inventories are archived or removed.

The button can render `templates/Invoke-GlpiPortableInventory.ps1` with these
values, then show the final PowerShell content for copy/paste into NinjaOne.

For the POC, the default GLPI Agent ZIP source is:

```text
https://github.com/glpi-project/glpi-agent/releases/download/1.17/GLPI-Agent-1.17-x64.zip
```

For production, replace this with an internal URL or pre-stage the ZIP in the
endpoint `packages` folder.

The script quotes process arguments before launching the portable BAT wrappers.
This is required because the default connector path lives under
`C:\Program Files (x86)\...`.

The script leaves the generated inventory `deviceid` unchanged. NinjaOne
identity is carried only through the inventory tag, for example
`NinjaOne:382`. Asset linking must be handled by GLPI inventory import and
linking rules using stable hardware fields such as serial number and UUID.

## Identity matching strategy

The plugin owns the durable NinjaOne-to-GLPI link in:

```text
glpi_plugin_ninjaone_devicemappings.ninjaone_device_id -> glpi_computers.id
```

The GLPI Agent inventory importer does not know this plugin table, so the first
match must be made through native GLPI asset identity fields. The intended order
is:

1. Existing plugin mapping by `ninjaone_device_id`.
2. GLPI computer serial number.
3. GLPI computer UUID.
4. Only then create a new GLPI computer.

The portable inventory script also sets the GLPI Agent tag to
`NinjaOne:<NINJA_AGENT_NODE_ID>` when NinjaOne exposes that environment
variable. This does not replace native GLPI matching, but it gives import rules
and troubleshooting screens a deterministic NinjaOne marker. If NinjaOne does
not expose the node ID during a run, the tag falls back to `NinjaOne`.
