import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

describe("command palette filtering", () => {
    const palette = readFileSync(
        resolve(
            process.cwd(),
            "resources/js/components/layout/command-palette.tsx",
        ),
        "utf8",
    );
    const postsGroup = readFileSync(
        resolve(
            process.cwd(),
            "resources/js/components/layout/command-palette/posts-group.tsx",
        ),
        "utf8",
    );

    it("uses cmdk filtering and keeps dynamic results searchable", () => {
        expect(palette).not.toContain("shouldFilter={false}");
        expect(palette).not.toContain("filter={");
        expect(palette).toContain(
            "keywords={[\n                                                trimmed,",
        );
        expect(palette).toContain("value={`account ${account.handle}`}");
        expect(postsGroup).toContain("const keywords = [query];");
        expect(postsGroup).toContain("keywords={keywords}");
        expect(postsGroup).not.toContain("forceMount");
    });
});
