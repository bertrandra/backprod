# ADR-032 — Geometry over core PostgreSQL, and the operation it refuses to fake

**Status:** accepted
**Decides:** how spatial questions are answered before a spatial backend exists
**Relates to:** Architecture V2 §19, §7, §10.2, §13; the last M7 deliverable

## Context

§19 is unusually explicit about what *not* to build. PostGIS "n'est pas une
dépendance initiale", phase 1 is PostgreSQL + GeoJSON with the current
project's calculations staying in the Core, and the section closes by naming
its own reason: "évite de dépendre d'une extension non confirmée sur
SiteGround." The roadmap turns that into the deliverable — a `GeoProvider`
interface "implemented over PostgreSQL, no PostGIS dependency, so a spatial
backend can be introduced later without the domain knowing."

§7 sketches three endpoints: `/geometry/intersections`, `/geometry/buffer`,
`/geometry/measure`. §10.2 puts all of `/geometry/*` behind the `gis.access`
entitlement with the Core or a Geo service as the business authority.

So the question was not whether to build a port — that was decided — but what
the port may promise when the thing behind it is a database that has had
geometric types since the 1990s and no spatial extension at all.

## Decision

**The port offers `measure` and `relate`, and no `buffer`.**

Core PostgreSQL 16 answers overlap, containment, distance, area, perimeter and
extent correctly. It has no buffer operation, and offsetting a ring by hand
would produce a shape that looks plausible and is wrong at every concave
corner and every self-approach. §19 names buffers among the things a spatial
extension becomes relevant *for*; that is the boundary, and it is drawn at the
port rather than papered over beneath it. `/geometry/buffer` therefore does
not exist yet, and a test asserts it returns 404 so that adding one is a
decision rather than an accident.

**What core PostgreSQL can do was measured, not assumed.** Older
documentation and long habit both suggest that `&&` and `@>` on polygons are
bounding-box approximations. In PostgreSQL 16 they are not: an L-shaped parcel
and a block sitting in its notch have overlapping bounding boxes, and `&&`
correctly answers false. `<->` is the true minimum separation, not the
distance between boxes. `@>` gets the hard concave case right too — a bar
whose every vertex lies inside a C-shape but whose body crosses the bay is
correctly reported as not contained. Each of these was run against a live
server before anything was built on it.

**The parser refuses more than it repairs, because the backend fails
silently.** Core PostgreSQL has no notion of a valid polygon:

- `polygon '((0,0),(1,1))'` parses. It is a line, and its area is zero.
- The bowtie `((0,0),(4,4),(4,0),(0,4))` measures **zero** area, because the
  shoelace sum cancels its two lobes. A surveyor who closed a plot the wrong
  way round would be told it has no surface.

Neither is a database error, so neither can be caught downstream. Both are
caught at the boundary or not at all, which is why `GeoJson` carries a
self-intersection test — the one piece of computational geometry this
platform does for itself. The same reasoning refuses the shapes core
PostgreSQL cannot represent: a polygon with a hole, a MultiPolygon, a Z
ordinate. Each has a tempting repair — keep the outer ring, take the first
part, drop the elevation — and each repair answers a different question from
the one that was asked.

**The caller states the coordinate reference, and it is never inferred.** A
parcel in Lambert-93 and the same parcel in WGS 84 are both pairs of finite
numbers, both inside the ranges a longitude and a latitude occupy. Guessing
from magnitude would be right most of the time, and a geometry engine that is
right most of the time about its own units reports a 1200 m² plot as
0.00000015.

So `crs` is required, and it decides what may be answered. `measure` refuses
`GEOGRAPHIC` with 422: a degree of longitude is not a fixed distance, and
converting would mean choosing a projection on the caller's behalf — in Web
Mercator at the latitude of Lille that choice inflates an area roughly 2.4
times. `intersections` accepts either, because topology does not depend on the
unit, and withholds `distance` in degrees rather than returning a number that
would be read as metres.

There is no unit in the response and no conversion anywhere. The platform
never learns whether a projected system is metres or feet; the caller chose
the projection and knows.

**The door is a capability, not a permission.** `GeoRoute` calls
`requireCapability('gis.access')` — the first place in the platform that does,
and the reason the mechanism existed. §13's distinction holds: a permission is
what a member's role lets them do with the tenant's data, a capability is what
the tenant bought. Geometry reads no row and writes none, so there is no role
question to ask. Two identical `TENANT_ADMIN`s in two tenants get different
answers here, and the one refused gets 403 `ENTITLEMENT_REQUIRED` rather than
404 — sending them to the offer page instead of to support.

## Consequences

This module adds **no migration**. It is the first feature here that stores
nothing, and that is worth stating plainly rather than leaving as an absence:
there is no tenant data to isolate because there is no tenant data at all, and
the isolation tests that would normally dominate a new surface are replaced by
one test about who may open the door.

Phase 2 of §19 moves geometry behind a separate service. When it arrives it is
another `GeoProvider` and one line of container wiring; `buffer` joins the
interface then, with a backend that can actually compute one. Nothing above
the port changes, which was the point of having one.

The refusals are strict enough that some legitimate callers will be turned
away — a parcel with a courtyard is a polygon with a hole, and this cannot
measure it. That is the correct trade while the backend cannot represent one.
A wrong area is worse than a refused one, because a refused one is visible.

`Row` gained `float`, `nullableFloat` and `boolean`; `JsonBody` gained
`requiredObjectList`. Both are general and were simply not needed until money
stopped being the only number the platform returned.
