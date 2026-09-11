# rc22 Design Brain + Rendered Fidelity

rc22 turns Design Core from an Elementor compiler/control layer into a local-first design engine with an explicit visual verification loop.

## Supported flow

`brief/reference -> Design Brief -> Design Intelligence -> Design System Profile -> Site/Page Strategy -> Section Recipes/Page Shells -> Design IR -> BuildPlan -> Elementor -> browser render -> visual/structural/UX QA -> verified correction or BuildPlan rebuild`

Design Core remains local-first and does not require MCP. Writes still use the governed BuildPlan/persistence path, except the restored V3 visual-correction service which only writes runtime-verified Elementor controls.

## What changed

- Design Intelligence v2 merges the curated starter catalog with 50+ real-world vertical profiles, including Veterinary, Medical, Dental, Legal, Property, Hospitality, Education, Automotive, Industrial, Creative, Community and Technology.
- Design Brief converts free-form requests into explicit goals, audiences, requested pages, reference mode and quality obligations.
- Page Strategy plans a complete site rather than only a homepage.
- Page Shell Library v2 provides 46 page archetypes while retaining schema-v1 and legacy shell compatibility.
- Section Recipe Library v2 provides 60+ reusable composition families while retaining legacy recipes.
- Screenshot capture, visual regression, Visual Feedback v3, UX audit and governed V3 visual correction are restored.
- `Visual_QA::compare_targets()` is functional again.
- Visual Quality Gate keeps architecture, responsive, visual, interaction and UX states separate. With a reference, `visual=unverified` blocks completion.
- Structural Diff detects section-order, large element-count, document-height and layout-pattern mismatches. Severe mismatches return `requires_rebuild=true` and must be rebuilt through BuildPlan instead of hidden behind CSS.

## Local agent commands

```bash
wp design-core-agent status
wp design-core-agent plan --brief="Create a trustworthy veterinary clinic website with booking and emergency care"
wp design-core-agent quality --page-id=123 --reference=/path/reference.html --candidate=http://127.0.0.1/page
wp design-core-agent visual_feedback /path/reference.html http://127.0.0.1/page --page-id=123 --target=0.95
wp design-core-agent visual_correct /path/reference.html http://127.0.0.1/page --page-id=123 --target=0.95 --iterations=4 --confirm=1
```

## Quality contract

For reference-driven work the recommended bounded loop is: plan -> preview BuildPlan -> disposable draft -> render at 1440/1280/1024/768/390 -> compare -> safe control correction or structural rebuild -> render again -> pass required quality dimensions -> promote.

Passing syntax, architecture, native-control validation or source-atom fidelity alone is not visual proof.
