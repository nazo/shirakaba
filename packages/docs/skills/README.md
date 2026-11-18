# Skills

Step-by-step workflows for coding agents working on Shirakaba applications ([static analysis and agents](../design/13-static-analysis-and-agents.md)). Each
directory holds a `SKILL.md` with a name and a description in its front matter, the format
Claude Code, Codex and other agents read. Copy or link the directories into your agent's
skills folder, for example:

```sh
mkdir -p .claude/skills && cp -R vendor/shirakaba/docs/skills/shirakaba-* .claude/skills/
```

| Skill | Use it to |
| --- | --- |
| [shirakaba-add-endpoint](shirakaba-add-endpoint/SKILL.md) | add an HTTP endpoint end to end |
| [shirakaba-add-entity](shirakaba-add-entity/SKILL.md) | add an entity, its relations and its migration |
| [shirakaba-audit-queries](shirakaba-audit-queries/SKILL.md) | find and fix N+1 queries |
| [shirakaba-add-job](shirakaba-add-job/SKILL.md) | add a background job or a scheduled task |

Facts (APIs, rules, design reasons) are in `vendor/shirakaba/docs/index.md`, not here; the
Skills point there.
