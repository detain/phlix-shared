# Class-Membership Idiom Survey — S-membership-survey-01

- **Date:** 2026-11-27
- **Anchor:** `0d8f951647a5c101bc0d7281075e3d17bd914874` (`test(relay): [S-shared-05] …` — HEAD when this survey's walk began, `origin/master` ahead 0).
  Honest churn note: the repo moved **during** the walk — a sibling landed
  `51cc6b2c` (`[S-shared-03-fix1]`, five Http docblock re-date lines, no line
  shifts in any cited region) and further WIP appeared. Every idiom grep below
  ran while HEAD was the anchor; the final re-verification of every cited
  `file:line` (Appendix A, "citation re-extract") ran against the **explicit
  anchor sha**, and `git merge-base --is-ancestor 0d8f951… HEAD` passes at
  writing time — so all line numbers are stable against `0d8f951…` regardless
  of where the moving tree goes next.
- **Produced by:** S-membership-survey-01 (program-derived survey; premise-class follow-up to `tests/Security/ConstantTimeComparisonCensusTest.php`)
- **Corpus:** tracked `src/**.php` at the anchor commit — 108 files
  (`git ls-tree -r HEAD --name-only | grep '^src/.*\.php$' | wc -l` = 108).
  This mirrors the census test's scope (`RecursiveDirectoryIterator` over `src/`,
  no excludes, vacuous-green refusal), with one deliberate difference: all line
  numbers in this document are **against the committed tree at the anchor sha**,
  derived via `git grep … HEAD -- src` (run while `0d8f951647…` was HEAD) and
  per-citation `git show 0d8f951647…:<path>` line re-extracts. The working tree
  carried uncommitted sibling WIP throughout (first probe: 31 files +107/−1, all
  docblock insertions; re-probe after the sibling's `51cc6b2c` commit: 38 files
  +174/−6 spanning README/src/tests) which shifts line numbers below its
  insertion points — citing the working tree would have made this document rot
  the moment that WIP landed.
- **Idiom definitions (as scoped by the step):**
  1. `in_array(` — any call; strictness (`true` third argument) recorded per site.
  2. `array_intersect`, `array_key_exists` — key-membership over arrays.
  3. `isset(` — recorded only where the operand is an array/offset whose
     membership gates behavior; classified pure-init vs gate.
  4. `instanceof ` — class-membership (hierarchy) tests.
  5. static-property presence idioms (`self::$x` guards — the `CurlArrTransport::$inFlight`
     shape and cache-init/age family).
  6. `preg_*` used as membership tests against a fixed marker set — documented
     and classified as **adjacent**, not core.
- **Explicit NON-GOAL:** no remediation is ordered anywhere in this document. It
  enumerates and assesses. **Enumeration is incomplete by construction** — the
  parent finding class (the constant-time census) proved the plan's enumeration
  premise false once already; this survey therefore reports every number together
  with the command that produced it (Appendix A) so any reader can re-derive it
  at the anchor, and names its own blind spots (Gaps section) rather than
  claiming closure.

## Why this family was out of scope before

`ConstantTimeComparisonCensusTest` pins (LAW 1) every built-in `hash_equals()`
call site in `src/` and (LAW 2) bans plaintext equality operators over
secret-named operands inside four law-declaring files. Its own neutral-arm list
(`GUARD_FUNCTIONS`) explicitly *whitelists* `in_array`, `preg_match`, `strlen`,
`count`, `isset`-family guards as acceptable shapes — i.e. the census's authors
consciously scoped membership/presence idioms **out** of the timing law because
they are (as this survey verifies) not per-byte early-exit comparisons against
secrets. That scoping-out was sound in analysis but never **measured**. This
document is the measurement: the full membership-idiom inventory, each site's
gate character, and the assessment of whether any partial/loose evaluation could
weaken a security decision.

## Inventory

Raw line counts are `git grep` matched-lines at the anchor (multi-line calls
count at their opening line; docblock prose mentions are listed separately).

| Idiom | Raw matched lines | Live call sites | Doc/prose mentions | Strictness / shape |
|---|---|---|---|---|
| `in_array(` | 14 | **12** | 2 (`StaticPathPolicy.php:80`, `Pkce.php:61` — both quote the strict form as law text) | **12/12 pass `true`** — zero loose calls (F-01) |
| `array_intersect` | 0 | 0 | 0 | — |
| `array_key_exists` | 20 | 20 | 0 | literal or `$key` param; hash lookup, no byte-compare |
| `isset(` | 22 | 19 | 3 (`ProviderInterface.php:40` doc example; `RowMap.php:442`–`:443` prose describing a retired pattern) | all on array/offset operands; classified below |
| `instanceof ` | 3 | 3 | 0 | hierarchy tests on value objects |
| `self::$` (static presence/guard idiom) | 43 | 38 | 5 (`CurlArrTransport.php:33`, `AuthResult.php:85`/`:97`, `RelayHttpRequest.php:233`, `JwtException.php:24` — docblock refs) | bool/`=== null` memo + guard flags |
| `preg_*` (adjacent) | 36 | 33 | 3 `use function` imports (`MigrationChecksum.php:16`–`:17`, `Pkce.php:18`) | all constant patterns, `=== 1` / `!== 1` strict comparison of result |
| *(not in step scope, recorded for completeness)* `?? ` array-existence lines | 99 | — | — | Gaps G-01 |
| *(adjacent)* `match (expr)` arms | 6 live (`MysqlRetryVerdict.php:85`, `RelayFrameType.php:231`, `RelayHttpRequestCodec.php:180`, `RelayHttpResponseCodec.php:180`, `SettingsCodec.php:103`, `:147`) | — | — | language-strict identity comparison; Gaps G-02 |
| *(adjacent)* `array_search` | 0 | 0 | — | — |

Gate-character split of the 51 live core-site rows (in_array 12 + AKE 20 +
isset 19), as classified site-by-case in the Findings: all **12** `in_array`
sites are vocabulary gates or the dev-assert belt (F-01/F-02 — deny-lists,
allow-lists, verb/header/scope/type/env vocabularies); of the **20** AKE sites,
**2** carry an adoption/authority presence decision (`Manifest.php:190` F-04,
`JwtClaims.php:147` F-05) and **18** are wire-DTO shape/required-field
validation (F-11, incl. the six `PayloadAssert` helpers); of the **19** live
`isset` sites, **12** gate behavior (F-09 gate list) and **7** are
shape/init/health probes (F-09 second list, F-07). Full line-by-line site tables are embedded in
each Finding; every cited line was re-extracted from `git show HEAD:<file>` in
the same pass that classified it (Appendix A, command 10).

## Findings

### F-01 — Zero loose `in_array` comparisons exist in `src/`

All 12 live call sites pass the strict flag. Sites (anchor-committed line
numbers, verbatim operand shapes):

| # | Site | Enclosing method | Needle | Haystack | Strict |
|---|---|---|---|---|---|
| 1 | `Http/StaticPathPolicy.php:84` (multi-line, `true` on `:87`) | `isDeniedStaticPath()` | local (computed `strtolower` of extension) | const `DENIED_STATIC_EXTENSIONS` `['php','map','tpl']` | ✓ |
| 2 | `Settings/SettingsCodec.php:197` | `assertValueType()` | param | const `VALUE_TYPES` | ✓ |
| 3 | `Relay/RelayHttpRequest.php:259` | `assertSafe()` | local (`strtoupper($this->method)`) | const `ALLOWED_METHODS` (7 verbs) | ✓ |
| 4 | `Relay/RelayHttpRequest.php:386` | `isForbiddenHeader()` | local (`strtolower(trim($name," \t"))`) | const `STRIPPED_HEADERS` `['x-phlix-relay-user','x-forwarded-for','authorization','cookie']` (`:95`) | ✓ |
| 5 | `Relay/SyncPlayDialect.php:150` | `isValidType()` | param | const `TYPES` via `self::all()` (18 wire types, `:113-133`) | ✓ |
| 6 | `Security/Hmac.php:97` | `assertFrozenSeparator()` | param | inline `[SEPARATOR_COLON, SEPARATOR_EQUALS]` | ✓ (inside `assert()` — see F-03) |
| 7 | `Security/OriginPolicy.php:143` | `localhostHost()` | local (`self::hostOf($host)`) | const `LOCALHOST_HOSTS` `['localhost','127.0.0.1','::1']` (`:83`) | ✓ |
| 8 | `Security/OriginPolicy.php:209` | `stateChangingMethod()` | param | const `STATE_CHANGING_METHODS` `['POST','PUT','DELETE','PATCH']` (`:90`) | ✓ |
| 9 | `Auth/JwtClaims.php:356` | `hasScope()` | param | instance prop `$this->scope` (`list<string>`) | ✓ |
| 10 | `Support/EnvConfig.php:154` | `boolValue()` | local (`strtolower(trim($raw))`) | const `TRUE_WORDS` `['1','true','yes','on']` (`:91`) | ✓ |
| 11 | `Support/EnvConfig.php:158` | `boolValue()` | same local | const `FALSE_WORDS` `['0','false','no','off']` (`:98`) | ✓ |
| 12 | `Plugin/Manifest.php:203` | `fromArray()` unknown-key loop | local (`(string)$key`) | const `KNOWN_TOP_LEVEL_KEYS` (8 keys, `:67-76`) | ✓ |

Assessment: membership here is string/list-set acceptance at trust boundaries
(static-file deny-list, relay header strip, CSRF verb predicate, plugin manifest
vocabulary, env bool grammar, OAuth scope grant). Loose comparison would change
observable behavior on type-juggle shapes (`"php"` vs numeric keys, magic `"0e…"`
strings); none of the 12 sites is loose. **No loose-comparison finding row
exists to file — this is the survey's primary negative result.**

### F-02 — Security-relevant membership sites (all strict, all exact-equality set semantics)

The 17 gate-character sites, grouped by what a match/absence decides:

- **Deny-gates (match ⇒ refuse):** `StaticPathPolicy:84` (file not served);
  `Manifest:203` (key reported `unknown_field` by the validator — note the
  membership itself only *collects*, enforcement is the consumer's);
  `RelayHttpRequest:386` (header stripped before forwarding — an untrusted relay
  producer cannot spoof `authorization`/`cookie`/identity).
- **Allow-gates (absence ⇒ refuse):** `RelayHttpRequest:259` (method rejected
  with throw); `SyncPlayDialect:150` (non-canonical wire type refused by the
  dialect latch); `OriginPolicy:209` (verb is CSRF-relevant only when in the
  state-changing set — GET/OPTIONS/HEAD fall through by design, docblock `:85-88`);
  `JwtClaims:356` (scope grant); `SettingsCodec:197` (unknown valueType throws).
- **Identity-grammar gates:** `OriginPolicy:143` (the §2.4 localhost limb —
  exact lowercase host list, any port); the `HostGrammar` isset/AKE cluster
  (F-11).
- **Config-bool gates:** `EnvConfig:154/158` (membership in the truth-word
  vocabulary decides effective bool; junk ⇒ default, fail-safe documented at
  `:100-106`).

Timing relevance, uniformly: PHP array key membership is a hash-table probe and
`in_array(..., true)` scans the (constant, public) needle list with full
string-equality — neither is a prefix/early-exit oracle over a *secret*, because
in every one of these 17 sites the **compared-against set is a public constant
vocabulary** and the attacker-supplied side is the needle. A "partial match
timing leak" would require the haystack entries to be secrets; none are
(`DENIED_STATIC_EXTENSIONS`, `STRIPPED_HEADERS`, `LOCALHOST_HOSTS`,
`STATE_CHANGING_METHODS`, scope lists, wire-type catalogs, env words are all
open vocabulary). The two runtime-argument haystacks (`$this->scope` at
`JwtClaims:356`, the manifest key loop) carry *grants/labels*, not secrets, and
a grant-membership timing side-channel has no exploitable oracle (the grant set
is client-visible in the token itself).

### F-03 — `Hmac.php:97` membership lives inside `assert()` — dev-mode-only enforcement

The frozen-separator law is expressed as `assert(in_array(..., true), …)`.
Under `zend.assertions=0`/`-1` production it is **compiled out** — the class
docblock (`:83-87`) states this openly as the BC law ("production keeps the
pre-guard wire behavior byte-for-byte"). Not a defect — an honesty note for any
future strictness-pin census: a pin that only exercises dev mode proves the
assert text, not production behavior. The actual production separator safety
route is that every live call site passes the constant (roster in the census's
LAW 1 neighborhood) — membership here is belt, not lock.

### F-04 — `Plugin/Manifest.php:190` — signature adoption is presence-keyed

`if (array_key_exists('signature', $data) && is_string($data['signature'])) {
$signature = $data['signature']; }`. The **presence** of the `signature` key
selects the signed arm of the manifest DTO (null ⇒ unsigned posture). The check
itself is an exact literal-key hash probe — timing-irrelevant, no partial-match
space. Verification of the value is the consumer's (server/hub verify layer);
this kernel only parses. Classified: state-relevant gate, by design, sound shape.

### F-05 — `Auth/JwtClaims.php:147` — `aud` legacy tolerance is a presence-as-authority site

`fromPayload()` reads: `aud` **absent** ⇒ default `AUD_SERVER` (`:140-157`
comment: migration path for legacy `JwtHandler` tokens; explicit-empty is
rejected). This is the one site in the family where a *membership fact* (is the
key present?) flips an authority-bearing value (which audience the token binds
to). Assessment: intentional and documented, the strict sibling
`fromPayloadStrict()` (`:176-181`) exists and refuses absence, and the key probe
is exact-match. If any future step promotes all issuers, the tolerant arm should
retire — that is a consumer-wave owner decision (reel), not a finding here.
Loose/`isset`-style reading would have conflated `aud: null` with absence —
the AKE-plus-`!== null` pair keeps them distinct; this is the reason AKE and
`isset` are separate tools at `:204/212/220/233` (optional claims treat
present-null as absent *value* but wrong-type as fatal).

### F-06 — `Security/Jwt.php:182` — `typ` enforcement is presence-conditional

`if (isset($header['typ'])) { … hash_equals(self::TYPE, $typ) … }` — an absent
`typ` skips the check; a present one must constant-time equal `'JWT'`. The
value compare is inside the census LAW-1 roster (pinned). Membership decision
itself is exact-key. `alg`, by contrast, is **required** (`:178-181`, `?? null`
+ `is_string` refusal) — the header field that actually gates the HMAC path has
no absence tolerance. Classified: sound, asymmetric by design.

### F-07 — `Arr` health checks gate on response-key presence (4 sites)

`SonarrClient.php:194`, `RadarrClient.php:263`, `ProwlarrClient.php:101`
(`isset($response['version'])`) and `BazarrClient.php:109`
(`isset($response['version']) || isset($response['bazarr'])`). `testConnection()`
answers "is this a live *arr API" from one member key of the authenticated
provider's own `/system/status` reply. Gates: operational health, not authority
(no decision downstream trusts a secret because the key was present; failure
path logs the redacted message). Timing: hash probe over provider-supplied keys.
Sound; listed for completeness.

### F-08 — Static-property guard family (presence-as-state idioms)

| Guard | Site(s) | What presence/flag gates | Assessment |
|---|---|---|---|
| `CurlArrTransport::$inFlight` | read `:136`, set `:143`, cleared in `finally` `:185` | re-entrancy refusal on the process-wide shared cURL handle (phlix-shared-001) — a RuntimeException **before any handle touch**, so a refused overlap never corrupts the live transfer | process-local bool; public state; no secret, no timing oracle. `?:`/truthiness on a `bool` prop — exact. |
| `CurlArrTransport::$handle` | null-init `:152`, assign `:157`, `curl_reset` `:159`, read `:162` | lazy resource init | pure init class; sound |
| `TrashGuidesProvider::$qualityProfilesCache/$customFormatsCache/$versionCache` | hit-reads `:227/:266/:305`, fills `:245/:284/:324`, clears `:379-382` | process-wide cache serving (TTL-fresh) guides JSON | pure cache-init class (the brief's named example) — confirmed: **none of these uses `isset()`; all are `!== null` identity** — membership is object-existence, not key lookup |
| `TrashGuidesProvider::$cacheTimestamp` | `=== null` `:395`, `>`-age `:399` vs const `CACHE_TTL_SECONDS`, writes `:246/:285/:325`, clear `:382` | expiry flush of the whole static cache | `ensureCacheValid()` (`:393-402`); arithmetic comparison on monotonically-increasing `hrtime` seconds — not byte-compare; no secret |
| `UpsertSyntax::$aliasSupported` | write `:55`, read `:76/:92`, reset `:82` | which SQL rewrite flavor `adapt()` applies (MySQL `AS new_row` vs legacy `VALUES()`) | **state-mutating gate** (rewrites SQL text), but the value is a process flavor set once by `detect()` from the server version banner (`supportsAlias()` `:61-71`: `stripos` mariadb probe + anchored `preg_match` shape + int compare) — version banner is public, comparisons are whole-value, not prefix |
| `SchemaPaths::$dirCache` | `=== null` `:49`, fill `:50`, read `:53` | memo of the schemas dir path | pure init |
| `EventNameMap::$fqcnToAlias`, `::$sortedAliases` | `=== null` `:119/:137`, fill `:122/:140`, read `:124/:143` | lazy memo of the alias↔FQCN dispatch vocabulary; `:124`'s `self::$fqcnToAlias[$fqcn] ?? null` is an array-existence idiom | pure init + `??` membership (Gaps G-01); key set is a public const map |

Docblock-only `self::$` mentions (`CurlArrTransport.php:33`,
`AuthResult.php:85/:97`, `RelayHttpRequest.php:233`, `JwtException.php:24`) are
prose references to readonly properties, not guards — counted, classified, no
security role beyond documenting the law.

### F-09 — `isset(` array memberships classified: gate vs init

- **Gate (12):** `HostGrammar.php:124/128` (optional `:port` preg-group in
  candidate grammar), `:149/152/160` (URL validity — userinfo/query/fragment
  **refusal**, port bounds), `RelayHttpRequest.php:278`
  (`isset($candidate[1])` — protocol-relative `//host` refusal per decode layer),
  `Jwt.php:182` (F-06), `ArrClientFactory.php:207` (present-but-invalid
  `connect_timeout` must throw while absence defaults), `TrashGuidesProvider.php:847/855/858/867`
  (`absoluteUrl()` rebuild of a redirect target's authority from `parse_url`
  limbs — the hop-limit law at `:811-822` + this re-anchoring is what stops a
  Location header from drifting the fetch origin silently).
- **Shape/init (7):** `Manifest.php:157/167/180` (events/settings coercion,
  settings entries without a string `type` dropped at the parse boundary),
  `SonarrClient.php:194`, `RadarrClient.php:263`, `ProwlarrClient.php:101`,
  `BazarrClient.php:109` (F-07).

None of the 19 has an attacker-secret on either side of the membership probe.

### F-10 — `instanceof` sites (3): type-vocabulary coercion, not gating

`RowMap.php:414` (`$value instanceof \DateTime` pass-through) and `:417`
(`\DateTimeInterface` → `DateTime::createFromInterface`) — DB-row value coercion;
the *absence* of both ⇒ `is_string` path ⇒ `new \DateTime($value)` parse with
`0000-00-00…`/blank refusal (`:424-437`). `QuotaExceeded.php:97`
(`$resetTimeUtc instanceof DateTimeImmutable`) selects the timezone-normalize
arm; the string arm faces the anchored ISO `preg_match` shape-probe (`:110-116`)
whose whole point is refusing relative-date prose (`"… next monday"`) reaching
the date parser. Hierarchy tests walk class/interface tables — no byte compare,
no secret operand. No security-gate character.

### F-11 — `Hub` DTO key-existence cluster (claim/heartbeat/server-info surfaces)

`ClaimRequest.php:77` (`publicKeysJwk` **required** — absence is fatal: federation
claim carries key material or it does not exist), `ServerInfoDto.php:104`
(`relayActive` required-bool), `:84/92/109/117` and `HeartbeatDto.php:77`,
`HostGrammar.php:81`, `JwtClaims.php:204/212/220/233` (documented optional-claim
arms). All exact literal-key AKEs on wire payloads; the *presence* semantics
(present-null vs absent distinguished deliberately) are the point. Assessment:
membership is validation-shape, not comparison; timing-irrelevant.

### F-12 — Adjacent class: `preg_*` as fixed-marker membership (33 live calls)

Representative sites (not every call is a membership shape — most are shape
probes over attacker-supplied text against **constant** patterns, with the
result compared strictly `=== 1` / `!== 1`): deny-style marker scans
`SubtitleFile.php:85` (separator/control-byte set → throw),
`MigrationChecksum.php:67` (comment-line markers); grammar accept probes
`Pkce.php:154/176` (`\A…\z` alphabets — the `\A`/`\z` law documented at
`:149-153` precisely because `$/m` membership would accept a trailing-newline
*partial match*; that is the one place in this whole survey where a
partial-evaluation hazard is both **live-class and already convicted by design**),
`Base64Url.php:136`, `Uuid.php:100` (const `V4_PATTERN`),
`HostGrammar.php:122/126/144/184/199`, `UpsertSyntax.php:66`,
`MysqlError.php:175`, `RadarrClient.php:170`; structure extraction over
provider/network-supplied text `TrashGuidesProvider.php:696/811/816`
(`HTTP/… <status>`, `Location:` marker), `:841` (scheme membership), `:906`
(commit-sha carve); redaction vocabulary `SecretRedactor.php:139/144/174/177`
(`BEARER_ENTROPY` — the prose-vs-key discriminator, value-presence semantics
disclosed in its own docblock `:153-164`); PEM marker strip/capture
`Ed25519Pem.php:190/298/338`. Assessment: patterns are constants, subjects are
inputs — timing side leaks pattern internals (public) not subject secrets; all
backtrack-free anchored shapes at a glance, and PCRE-linear. Classified
**adjacent** per step scope; no site compares a secret subject against a secret
pattern.

## Gaps & follow-up proposals

Enumeration is by-grep-of-six-idioms; the following are named, un-exhaustive
blind spots a future census-extension step could inherit:

- **G-01 (measured):** 99 `src/` lines contain `?? ` — the null-coalesce family
  is the *same* presence idiom as `isset`/AKE on arrays (e.g.
  `EventNameMap.php:124`, `RadarrClient.php:164`,
  `TrashGuidesProvider.php:234/273/312` config URL reads) and was **not**
  walked site-by-site here. Proposal: a census-extension step over
  `?? `/`?:` array reads at trust boundaries.
- **G-02 (measured):** 6 live `match (expr)` arms (Inventory table) — PHP `match`
  uses strict `===` arms by language definition, so the loose-compare worry is
  structurally absent; recorded for roster completeness, no action implied.
- **G-03:** strictness pin for `in_array` — the walk found 12/12 strict today;
  **`src/Security/` carries live instances** (`Hmac.php:97`,
  `OriginPolicy.php:143`, `OriginPolicy.php:209`) plus the law-quoting docblocks
  (`Pkce.php:61` names the *rejected* loose shapes). A mechanical
  "every `in_array` in `src/Security/` carries `true`" pin (same file-iterator
  semantics as the constant-time census) would make F-01's negative result
  permanent instead of re-measured by hand. Named candidates:
  `Security/Hmac.php:97`, `Security/OriginPolicy.php:143`,
  `Security/OriginPolicy.php:209`. Proposal only — this step orders no work.
- **G-04:** this survey's counts are grep-of-line, not token-level (the census
  test's `token_get_all` sees docblock text too and had to *subtract* imports;
  here doc mentions were classified by reading each one). A token-level walker
  is the right tool for any successor pin.
- **G-05:** value-side membership beyond top-level keys (e.g. nested `type`
  vocabularies validated by consumers outside `src/` of this package —
  `Manifest.php:180` drops entries whose `type` is missing but never checks the
  *word* set; that vocabulary lives in the consuming schema validators in
  server/hub) is out of corpus by definition and un-counted.

## Verdict

**No.** Across the 51 live core membership sites (12 `in_array`, 20
`array_key_exists`, 19 `isset`) and the 3 `instanceof` plus the static-guard
family walked at the anchor commit, there is **no security-relevant comparison
whose partial or loose evaluation could weaken a decision**: every `in_array`
call in `src/` is strict; every array-membership probe (AKE/isset/`??`/`in_array`
needle scan) faces either a **public constant vocabulary** (deny-lists, verb
sets, header names, wire types, env words, claim keys) or a **client-visible
grant list**, against an attacker-supplied needle — the direction in which a
prefix/timing oracle has nothing to leak; the key probes are hash-table lookups,
not byte-wise compares; `instanceof` walks class tables; the static guards are
process-local bool/null memos over public state; and the `preg_*` adjacent class
matches constant patterns with `=== 1`-compared results. The two
presence-*is*-authority shapes the survey did surface — `JwtClaims.php:147`
(`aud` absence ⇒ server-audience default) and `Plugin/Manifest.php:190`
(signature presence ⇒ signed arm) — are exact-key, by-design, and each already
carries a strict sibling or consumer-side verifier. The plan's enumeration
premise remains falsified in the honest direction: this family is now measured,
but with G-01/G-05 stating exactly what remains unmeasured, and the commands in
Appendix A making every number above re-derivable.

## Appendix A — Commands and raw output (anchor `0d8f951`)

All executed in `/home/sites/phlix/phlix-shared`; scratch copies under
`/tmp/opencode/phlix-exec-1124/membership-survey/`.

```text
$ git ls-tree -r HEAD --name-only | grep '^src/.*\.php$' | wc -l
108
$ git grep -nE 'in_array\('            HEAD -- src | wc -l   # 14 lines: 12 live + 2 doc (StaticPathPolicy.php:80, Pkce.php:61)
$ git grep -nE 'array_intersect'        HEAD -- src | wc -l   # 0
$ git grep -nE 'array_key_exists'       HEAD -- src | wc -l   # 20
$ git grep -nE 'isset\('                HEAD -- src | wc -l   # 22 lines: 19 live + 3 doc (ProviderInterface.php:40; RowMap.php:442,:443)
$ git grep -nE 'instanceof '            HEAD -- src | wc -l   # 3
$ git grep -nE 'self::\$'               HEAD -- src | wc -l   # 43 lines: 38 live + 5 doc
$ git grep -nE 'preg_(match|match_all|replace|replace_callback|split|grep)' HEAD -- src | wc -l
36              # 33 live calls + 3 `use function` import lines
$ git grep -nE 'array_search'           HEAD -- src | wc -l   # 0
$ git grep -cE '\?\?' HEAD -- src | awk -F: '{s+=$3} END {print s}'
99              # lines (not occurrences) containing ??
$ git grep -nE '\bmatch\s*\(' HEAD -- src
6 live match-expression arms (listed in Inventory table), plus 1 method
  named match() (Security/CredentialDigest.php:59 — internally hash_equals,
  already LAW-1 rostered) and 2 prose mentions.
$ git grep -nE 'in_array\(' HEAD -- src | sed -E 's/^HEAD://'
  # full 14-line output reviewed line-by-line; all 12 call lines carry ", true"
  # (StaticPathPolicy spans :84-:88 with the flag on :87 — verified by
  #  git show HEAD:src/Http/StaticPathPolicy.php window read)
```

Verification method for every cited `file:line`: the cited line was
re-extracted from the **anchor blob** — `git show
0d8f951647a5c101bc0d7281075e3d17bd914874:<path> | sed -n '<N>p'` — in the same
session pass that wrote its classification (two batches: the 48 `in_array`/AKE
site lines, then an 88-line full-citation re-extract retained at
`/tmp/opencode/phlix-exec-1124/membership-survey/verified_lines.txt`; zero
fatal paths, zero empty extracts). Quoted content in the Findings tables comes
from those extracts, never from the working tree (which carried the moving
sibling WIP described in the corpus note). Descendant state at writing time:
`git merge-base --is-ancestor 0d8f951647… HEAD` → true (HEAD then `51cc6b2c`).

The file is a pure document — no PHP tags; nothing in the repo's behavior,
tests, or counts changes by its presence.
