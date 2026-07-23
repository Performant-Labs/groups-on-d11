## Brief Review (Round 1)

### BLOCK findings  
**[B-1]** Enforcement mechanism unverified — the brief says enforcement must happen “via Group access at the `group_membership.create` operation” and that a new hook class is needed because “no existing `group_access` hook in `do_group_membership`.” We must confirm that the `GroupMembershipManager` (or equivalent) actually provides hook points for create, update and delete authorization. If it does not, the design is unimplementable; you need to either extend the manager to add those hooks or choose a different enforcement layer.  

**[B-2]** Undefined “organizer” role/permission — AC-4 refers to “Organizer on Leadership Council” but never defines which group role(s) or permission(s) qualify a user as an organizer or grant access to the pending-requests queue and the approve/deny actions. You must specify the exact group role name(s) or permission code(s) that gate viewing `/group/{group}/members/pending` and performing status updates.  

**[B-3]** No guard on unauthorized approve/deny routes — AC-3 and AC-11 cover forbidden direct POSTs to join-request on invite-only groups, but there is no acceptance criterion ensuring that non-organizers (or anonymous users) cannot invoke the approve or deny endpoints (e.g. `PATCH /group/{group}/membership/{id}`, `DELETE /group/{group}/membership/{id}`). You must add ACs (and tests) that direct HTTP requests to the approve/deny paths by unauthorized actors return 403.  

**[B-4]** Missing spec for default roles on approval — AC-2 assures pending requests have no `group_roles`; AC-4 says “Approve → active membership visible” but does not specify what role(s) are assigned to an approved member. Without a clear default (`member`? `contributor`? none?), implementations will diverge and newly approved members may lack expected permissions. Clarify the post-approval role assignment.  

**[B-5]** Seeding idempotency undefined — AC-9 requires appending to `step_700_demo_data.php` “idempotently” to set each group’s policy and create one pending request. But the brief does not instruct how the seed script should detect existing rows to avoid duplicates or errors on re-run. You must prescribe existence checks or `upsert` logic in the seeding steps.  

### WARN findings  
**[W-1]** Composite `field_group_visibility` may be confusing — using a single field for both visibility and join policy can lead to ambiguous state and increase maintenance burden. Consider documenting all allowed values and their semantics clearly, or revisit splitting visibility and policy soon.  

**[W-2]** WCAG validation is partly manual — AC-12 delegates contrast and focus testing to a play-through plus axe audit. To guard against regressions, consider integrating automated axe checks in the CI pipeline for the new UI components.  

**[W-3]** E2E locator brittleness — the specified locators (`role=button,name=/Request to join/i` or `input[type=submit][value*=Request]`) may miss a `<button type=submit>` with inner text. Make sure your playwright selectors cover both patterns explicitly.  

**[W-4]** HelpText copy tests may be brittle — AC-6/7/8 lock in exact words (“Not yet enforced”, “visible”). If translations or minor phrasing changes occur, tests will break. Consider pinning to key-based expectations rather than substring matches.  

### NIT findings  
**[NIT-1]** Inconsistent naming — “Leadership Council” and “Core Committers” sometimes appear bolded, sometimes plain. Pick one style for clarity.  
**[NIT-2]** Issue title “SC-2 Membership models enforced” is terse; something like “Enforce open/request/invite join policies” might read more clearly.  
**[NIT-3]** The “Wave: W1 (foundation)” label could include sprint or date for future reference.  
**[NIT-4]** References to “HelpText.php lines 84–87” will drift; consider linking to a blob URL or stable anchor.  
**[NIT-5]** In “Reuse & Analogous-Feature map,” the path `do_group_membership` isn’t fully qualified. Supply full namespace or file path to avoid ambiguity.  

### Verdict  
BLOCK — 5 blocking finding(s); must resolve before implementation starts.
