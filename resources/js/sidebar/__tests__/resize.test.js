import { SidebarResizer } from '../resize.js';





describe('coalesces repeated events into a single handled call', () => {
    let resizer;
    let root;

    beforeEach(() => {
        jest.useFakeTimers();
        localStorage.clear();

        root = document.createElement('div');
        root.id = 'plathix-sidebar-root';
        document.body.appendChild(root);

        resizer = new SidebarResizer('attachment');



        jest.advanceTimersByTime(20);
    });

    afterEach(() => {
        resizer.destroy();
        root.remove();
        jest.useRealTimers();
    });

    it('coalesces repeated events into a single handled call', () => {
        const rectSpy = jest.spyOn(root, 'getBoundingClientRect');
        rectSpy.mockClear();

        window.dispatchEvent(new Event('resize'));
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));


        expect(rectSpy).not.toHaveBeenCalled();

        jest.advanceTimersByTime(20);

        expect(rectSpy).toHaveBeenCalledTimes(1);
    });

    it('coalesces repeated events into a single handled call', () => {
        const rectSpy = jest.spyOn(root, 'getBoundingClientRect');
        rectSpy.mockClear();

        window.dispatchEvent(new Event('resize'));
        jest.advanceTimersByTime(20);

        expect(rectSpy).toHaveBeenCalledTimes(1);
    });

    it('coalesces repeated events into a single handled call', () => {
        const rectSpy = jest.spyOn(root, 'getBoundingClientRect');
        rectSpy.mockClear();

        window.dispatchEvent(new Event('resize'));
        resizer.destroy();
        jest.advanceTimersByTime(20);

        expect(rectSpy).not.toHaveBeenCalled();
    });
});
