import { safeHexColor, colorShowImpl } from '../color-show.js';

describe('validates color input and keeps the color picker in sync with the store', () => {
    test('validates color input and keeps the color picker in sync with the store', () => {
        expect(safeHexColor('#2271b1')).toBe('#2271b1');
        expect(safeHexColor('#FFFFFF')).toBe('#FFFFFF');
    });

    test('validates color input and keeps the color picker in sync with the store', () => {
        expect(safeHexColor('#fff')).toBe('#fff');
        expect(safeHexColor('#ABC')).toBe('#ABC');
    });

    test('validates color input and keeps the color picker in sync with the store', () => {
        expect(safeHexColor('2271b1')).toBeNull();
        expect(safeHexColor('')).toBeNull();
        expect(safeHexColor('expression(alert(1))')).toBeNull();
        expect(safeHexColor('#fff; background:url(x)')).toBeNull();
        expect(safeHexColor(null)).toBeNull();
        expect(safeHexColor(123)).toBeNull();
    });
});

describe('keeps store/selection state consistent across UI events', () => {
    test('preserves folder tree behavior', () => {
        expect(colorShowImpl.folderColorStyle({ color: '#ff8800' })).toBe('color:#ff8800');
        expect(colorShowImpl.folderColorStyle({ color: '' })).toBe('');
        expect(colorShowImpl.folderColorStyle({})).toBe('');
    });

    test('preserves folder tree behavior', () => {
        expect(colorShowImpl.folderColorFill({ color: '#ff8800' })).toBe('#ff880033');
        expect(colorShowImpl.folderColorFill({ color: '' })).toBe('none');
    });
});
