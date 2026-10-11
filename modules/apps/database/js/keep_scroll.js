// Database tab: opening a table reloads the page, which would jump the
// Tables list and the page back to the top. A table link saves both scroll
// positions just before it navigates, and the next page puts them back when
// it is the page that link opened. Links say so with data-keep-scroll.
(function () {
    const storageKey = 'tcloud.database.scroll';

    function tablesList() {
        return document.querySelector('.db-tables ul');
    }

    function savePositions(link) {
        const list = tablesList();
        const positions = {
            href: link.href,
            page: window.scrollY,
            list: list ? list.scrollTop : 0
        };

        try {
            sessionStorage.setItem(storageKey, JSON.stringify(positions));
        } catch (error) {
            // Private windows can refuse storage; the page then opens at the top
        }
    }

    function takeSavedPositions() {
        let saved = null;

        try {
            saved = sessionStorage.getItem(storageKey);
            sessionStorage.removeItem(storageKey);
        } catch (error) {
            return null;
        }

        if (saved === null) {
            return null;
        }

        return JSON.parse(saved);
    }

    function restorePositions() {
        const positions = takeSavedPositions();
        if (positions === null) {
            return;
        }

        if (positions.href !== window.location.href) {
            return;
        }

        const list = tablesList();
        if (list) {
            list.scrollTop = positions.list;
        }

        window.scrollTo(0, positions.page);
    }

    function onClick(event) {
        const link = event.target.closest('a[data-keep-scroll]');
        if (!link) {
            return;
        }

        savePositions(link);
    }

    document.addEventListener('click', onClick);
    restorePositions();
})();
