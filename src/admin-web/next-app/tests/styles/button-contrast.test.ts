import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const css=readFileSync(resolve(process.cwd(),"app/globals.css"),"utf8");
function rgb(hex:string) { return [1,3,5].map(index=>parseInt(hex.slice(index,index+2),16)); }
function luminance(color:number[]) { return color.map(value=>{const channel=value/255;return channel<=0.04045?channel/12.92:((channel+0.055)/1.055)**2.4;}).reduce((sum,value,index)=>sum+value*[0.2126,0.7152,0.0722][index],0); }
function contrast(first:number[],second:number[]) { const a=luminance(first),b=luminance(second);return (Math.max(a,b)+0.05)/(Math.min(a,b)+0.05); }
function variable(name:string) { const value=css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`))?.[1];if(!value)throw new Error(`Missing color ${name}`);return rgb(value); }

describe("shared button text contrast",()=>{
  it.each(["primary","secondary","danger"])("keeps white %s text readable across every gradient stop",kind=>{
    const from=variable(`${kind}-start`),to=variable(`${kind}-end`);
    const ratios=Array.from({length:101},(_,index)=>contrast([255,255,255],from.map((value,channel)=>value+(to[channel]-value)*index/100)));
    expect(Math.min(...ratios)).toBeGreaterThanOrEqual(4.5);
  });
  it("does not reduce contrast with hover filtering or disabled opacity and retains focus visibility",()=>{
    const hover=css.match(/button:hover:not\(:disabled\)\s*\{([^}]+)\}/)?.[1]??"";
    const disabled=css.match(/button\.danger:disabled\s*\{([^}]+)\}/)?.[1]??"";
    const foreground=disabled.match(/\bcolor:\s*(#[0-9a-fA-F]{6})/)?.[1];
    const background=disabled.match(/\bbackground:\s*(#[0-9a-fA-F]{6})/)?.[1];
    expect(hover).toMatch(/filter:\s*none/);
    expect(disabled).toMatch(/opacity:\s*1\s*;/);
    expect(foreground).toBeTruthy();expect(background).toBeTruthy();
    expect(contrast(rgb(foreground!),rgb(background!))).toBeGreaterThanOrEqual(4.5);
    expect(css).toMatch(/:focus-visible[^{]*\{[^}]*outline:\s*3px solid/);
  });
});
