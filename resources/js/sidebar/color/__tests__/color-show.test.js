import { safeHexColor, colorShowImpl } from '../color-show.js';

describe('safeHexColor (перенесён в модуль color/ — [internal])', () => {
    test('принимает 6-значный hex', () => {
        expect(safeHexColor('#2271b1')).toBe('#2271b1');
        expect(safeHexColor('#FFFFFF')).toBe('#FFFFFF');
    });

    test('принимает 3-значный hex', () => {
        expect(safeHexColor('#fff')).toBe('#fff');
        expect(safeHexColor('#ABC')).toBe('#ABC');
    });

    test('отклоняет строку без #, пустую, CSS-injection, не-строки', () => {
        expect(safeHexColor('2271b1')).toBeNull();
        expect(safeHexColor('')).toBeNull();
        expect(safeHexColor('expression(alert(1))')).toBeNull();
        expect(safeHexColor('#fff; background:url(x)')).toBeNull();
        expect(safeHexColor(null)).toBeNull();
        expect(safeHexColor(123)).toBeNull();
    });
});

describe('colorShowImpl — реализация показа для домёржа в store', () => {
    test('folderColorStyle: заданный цвет → style, пустой → пусто', () => {
        expect(colorShowImpl.folderColorStyle({ color: '#ff8800' })).toBe('color:#ff8800');
        expect(colorShowImpl.folderColorStyle({ color: '' })).toBe('');
        expect(colorShowImpl.folderColorStyle({})).toBe('');
    });

    test('folderColorFill: заданный цвет → fill+альфа, пустой → none', () => {
        expect(colorShowImpl.folderColorFill({ color: '#ff8800' })).toBe('#ff880033');
        expect(colorShowImpl.folderColorFill({ color: '' })).toBe('none');
    });
});
